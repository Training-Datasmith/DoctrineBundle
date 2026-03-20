<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use function sprintf;
use Symfony\Component\Dependency_Injection\Child_Definition;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Reference;
/**
 * Processes the doctrine.dbal.schema_filter
 *
 * @internal
 */
final class Dbal_Schema_Filter_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        $filters = $container->find_tagged_service_ids('doctrine.dbal.schema_filter');
        $connection_filters = [];
        foreach ($filters as $id => $tag_attributes) {
            foreach ($tag_attributes as $attributes) {
                $name = $attributes['connection'] ?? $container->get_parameter('doctrine.default_connection');
                if (!isset($connection_filters[$name])) {
                    $connection_filters[$name] = [];
                }
                $connection_filters[$name][] = new Reference($id);
            }
        }
        foreach ($connection_filters as $name => $references) {
            $configuration_id = sprintf('doctrine.dbal.%s_connection.configuration', $name);
            if (!$container->has_definition($configuration_id)) {
                continue;
            }
            $definition = new Child_Definition('doctrine.dbal.schema_asset_filter_manager');
            $definition->set_argument(0, $references);
            $id = sprintf('doctrine.dbal.%s_schema_asset_filter_manager', $name);
            $container->set_definition($id, $definition);
            $container->find_definition($configuration_id)->add_method_call('setSchemaAssetsFilter', [new Reference($id)]);
        }
    }
}