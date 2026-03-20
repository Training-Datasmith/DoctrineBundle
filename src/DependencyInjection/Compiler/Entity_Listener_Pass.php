<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use Doctrine\Bundle\Doctrine_Bundle\Mapping\Container_Entity_Listener_Resolver;
use Doctrine\Bundle\Doctrine_Bundle\Mapping\Entity_Listener_Service_Resolver;
use function is_a;
use function method_exists;
use function sprintf;
use Symfony\Component\Dependency_Injection\Child_Definition;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Compiler\Service_Locator_Tag_Pass;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Definition;
use Symfony\Component\Dependency_Injection\Exception\InvalidArgumentException;
use Symfony\Component\Dependency_Injection\Reference;
use function usort;
/**
 * Class for Symfony bundles to register entity listeners
 *
 * @internal
 */
final class Entity_Listener_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        $lazy_service_references_by_resolver = [];
        $service_tags = [];
        foreach ($container->find_tagged_service_ids('doctrine.orm.entity_listener', true) as $id => $tags) {
            foreach ($tags as $attributes) {
                $service_tags[] = ['serviceId' => $id, 'attributes' => $attributes];
            }
        }
        usort($service_tags, static fn(array $a, array $b): int => ($b['attributes']['priority'] ?? 0) <=> ($a['attributes']['priority'] ?? 0));
        foreach ($service_tags as $tag) {
            $id = $tag['serviceId'];
            $attributes = $tag['attributes'];
            $name = $attributes['entity_manager'] ?? $container->get_parameter('doctrine.default_entity_manager');
            $entity_manager = sprintf('doctrine.orm.%s_entity_manager', $name);
            if (!$container->has_definition($entity_manager)) {
                continue;
            }
            $resolver_id = sprintf('doctrine.orm.%s_entity_listener_resolver', $name);
            if (!$container->has($resolver_id)) {
                continue;
            }
            $resolver = $container->find_definition($resolver_id);
            $resolver->set_public(true);
            if (isset($attributes['entity'])) {
                $this->attach_to_listener($container, $name, $this->get_concrete_definition_class($container->find_definition($id), $container, $id), $attributes);
            }
            $resolver_class = $this->get_resolver_class($resolver, $container, $resolver_id);
            $resolver_supports_lazy_listeners = is_a($resolver_class, Entity_Listener_Service_Resolver::class, true);
            $lazy_by_attribute = isset($attributes['lazy']) && $attributes['lazy'];
            if ($lazy_by_attribute && !$resolver_supports_lazy_listeners) {
                throw new InvalidArgumentException(sprintf('Lazy-loaded entity listeners can only be resolved by a resolver implementing %s.', Entity_Listener_Service_Resolver::class));
            }
            if (!isset($attributes['lazy']) && $resolver_supports_lazy_listeners || $lazy_by_attribute) {
                $listener = $container->find_definition($id);
                $resolver->add_method_call('registerService', [$this->get_concrete_definition_class($listener, $container, $id), $id]);
                // if the resolver uses the default class we will use a service locator for all listeners
                if ($resolver_class === Container_Entity_Listener_Resolver::class) {
                    if (!isset($lazy_service_references_by_resolver[$resolver_id])) {
                        $lazy_service_references_by_resolver[$resolver_id] = [];
                    }
                    $lazy_service_references_by_resolver[$resolver_id][$id] = new Reference($id);
                } else {
                    $listener->set_public(true);
                }
            } else {
                $resolver->add_method_call('register', [new Reference($id)]);
            }
        }
        foreach ($lazy_service_references_by_resolver as $resolver_id => $listener_references) {
            $container->find_definition($resolver_id)->set_argument(0, Service_Locator_Tag_Pass::register($container, $listener_references));
        }
    }
    /** @param array{entity: class-string, event?: ?string, method?: string} $attributes */
    private function attach_to_listener(Container_Builder $container, string $name, string $class, array $attributes): void
    {
        $listener_id = sprintf('doctrine.orm.%s_listeners.attach_entity_listeners', $name);
        if (!$container->has($listener_id)) {
            return;
        }
        $args = [$attributes['entity'], $class, $attributes['event'] ?? null];
        if (isset($attributes['method'])) {
            $args[] = $attributes['method'];
        } elseif (isset($attributes['event']) && !method_exists($class, $attributes['event']) && method_exists($class, '__invoke')) {
            $args[] = '__invoke';
        }
        $container->find_definition($listener_id)->add_method_call('addEntityListener', $args);
    }
    private function get_resolver_class(Definition $resolver, Container_Builder $container, string $id): string
    {
        $resolver_class = $this->get_concrete_definition_class($resolver, $container, $id);
        if (str_starts_with($resolver_class, '%')) {
            // resolve container parameter first
            return $container->get_parameter_bag()->resolve_value($resolver_class);
        }
        return $resolver_class;
    }
    private function get_concrete_definition_class(Definition $definition, Container_Builder $container, string $id): string
    {
        $class = $definition->get_class();
        if ($class) {
            return $class;
        }
        while ($definition instanceof Child_Definition) {
            $definition = $container->find_definition($definition->get_parent());
            $class = $definition->get_class();
            if ($class) {
                return $class;
            }
        }
        throw new InvalidArgumentException(sprintf('The service "%s" must define its class.', $id));
    }
}