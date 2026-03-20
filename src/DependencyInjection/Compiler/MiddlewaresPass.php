<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use Doctrine\Bundle\Doctrine_Bundle\Middleware\Connection_Name_Aware_Interface;
use function is_subclass_of;
use function sprintf;
use Symfony\Component\Dependency_Injection\Child_Definition;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Reference;
use function usort;
/** @internal */
final class Middlewares_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        if (!$container->has_parameter('doctrine.connections')) {
            return;
        }
        $middleware_abstract_defs = [];
        $middleware_connections = [];
        $middleware_priorities = [];
        foreach ($container->find_tagged_service_ids('doctrine.middleware') as $id => $tags) {
            $middleware_abstract_defs[$id] = $container->get_definition($id);
            // When a def has doctrine.middleware tags with connection attributes equal to connection names
            // registration of this middleware is limited to the connections with these names
            foreach ($tags as $tag) {
                if (!isset($tag['connection'])) {
                    if (isset($tag['priority']) && !isset($middleware_priorities[$id])) {
                        $middleware_priorities[$id] = $tag['priority'];
                    }
                    continue;
                }
                $middleware_connections[$id][$tag['connection']] = $tag['priority'] ?? null;
            }
        }
        foreach (array_keys($container->get_parameter('doctrine.connections')) as $name) {
            $middleware_refs = [];
            $i = 0;
            foreach ($middleware_abstract_defs as $id => $abstract_def) {
                if (isset($middleware_connections[$id]) && !array_key_exists($name, $middleware_connections[$id])) {
                    continue;
                }
                $child_def = $container->set_definition($child_id = sprintf('%s.%s', $id, $name), (new Child_Definition($id))->set_tags($abstract_def->get_tags())->clear_tag('doctrine.middleware')->set_autoconfigured($abstract_def->is_autoconfigured())->set_autowired($abstract_def->is_autowired()));
                $middleware_refs[$id] = [new Reference($child_id), ++$i];
                $class = $abstract_def->get_class();
                if ($class === null) {
                    continue;
                }
                if (!is_subclass_of($class, Connection_Name_Aware_Interface::class)) {
                    continue;
                }
                $child_def->add_method_call('setConnectionName', [$name]);
            }
            $middleware_refs = array_map(static fn(string $id, array $ref): array => [$middleware_connections[$id][$name] ?? $middleware_priorities[$id] ?? 0, $ref[1], $ref[0]], array_keys($middleware_refs), array_values($middleware_refs));
            usort($middleware_refs, static fn(array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
            $middleware_refs = array_map(static fn(array $value): Reference => $value[2], $middleware_refs);
            $container->get_definition(sprintf('doctrine.dbal.%s_connection.configuration', $name))->add_method_call('setMiddlewares', [$middleware_refs]);
        }
    }
}