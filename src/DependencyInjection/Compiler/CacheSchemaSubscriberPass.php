<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use Symfony\Component\Cache\Adapter\Doctrine_Dbal_Adapter;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Reference;
/**
 * Injects Doctrine DBAL adapters into their schema subscriber.
 *
 * Must be run later after ResolveChildDefinitionsPass.
 *
 * @internal
 */
final class Cache_Schema_Subscriber_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        if (!$container->has_definition('doctrine.orm.listeners.doctrine_dbal_cache_adapter_schema_listener')) {
            return;
        }
        $subscriber = $container->get_definition('doctrine.orm.listeners.doctrine_dbal_cache_adapter_schema_listener');
        $cache_adapters_references = [];
        foreach ($container->get_definitions() as $id => $definition) {
            if ($definition->is_abstract()) {
                continue;
            }
            if ($definition->is_synthetic()) {
                continue;
            }
            if ($definition->get_class() !== Doctrine_Dbal_Adapter::class) {
                continue;
            }
            $cache_adapters_references[] = new Reference($id);
        }
        $subscriber->replace_argument(0, $cache_adapters_references);
    }
}