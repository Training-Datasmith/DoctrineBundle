<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use function array_combine;
use function array_keys;
use function array_map;
use Doctrine\Bundle\Doctrine_Bundle\Mapping\Class_Metadata_Factory;
use Doctrine\Bundle\Doctrine_Bundle\Mapping\Mapping_Driver;
use Doctrine\ORM\Mapping\Class_Metadata_Factory as ORMClassMetadataFactory;
use function sprintf;
use Symfony\Component\Dependency_Injection\Alias;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Compiler\Service_Locator_Tag_Pass;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Reference;
/** @internal */
final class Id_Generator_Pass implements Compiler_Pass_Interface
{
    public const string ID_GENERATOR_TAG = 'doctrine.id_generator';
    public const string CONFIGURATION_TAG = 'doctrine.orm.configuration';
    public function process(Container_Builder $container): void
    {
        $generator_ids = array_keys($container->find_tagged_service_ids(self::ID_GENERATOR_TAG));
        // when ORM is not enabled
        if (!$container->has_definition('doctrine.orm.configuration') || !$generator_ids) {
            return;
        }
        $generator_refs = array_map(static fn(string $id): Reference => new Reference($id), $generator_ids);
        $ref = Service_Locator_Tag_Pass::register($container, array_combine($generator_ids, $generator_refs));
        $container->set_alias('doctrine.id_generator_locator', new Alias((string) $ref, false));
        foreach ($container->find_tagged_service_ids(self::CONFIGURATION_TAG) as $id => $tags) {
            $configuration_def = $container->get_definition($id);
            $method_calls = $configuration_def->get_method_calls();
            $metadata_driver_impl = null;
            foreach ($method_calls as $i => [$method, $arguments]) {
                if ($method === 'setMetadataDriverImpl') {
                    $metadata_driver_impl = (string) $arguments[0];
                }
                if ($method !== 'setClassMetadataFactoryName') {
                    continue;
                }
                if ($arguments[0] !== Orm_Class_Metadata_Factory::class && $arguments[0] !== Class_Metadata_Factory::class) {
                    $class = $container->get_reflection_class($arguments[0]);
                    if ($class && $class->is_subclass_of(Class_Metadata_Factory::class)) {
                        break;
                    }
                    continue 2;
                }
                $method_calls[$i] = ['setClassMetadataFactoryName', [Class_Metadata_Factory::class]];
            }
            if ($metadata_driver_impl === null) {
                continue;
            }
            $configuration_def->set_method_calls($method_calls);
            $container->register('.' . $metadata_driver_impl, Mapping_Driver::class)->set_decorated_service($metadata_driver_impl)->set_arguments([new Reference(sprintf('.%s.inner', $metadata_driver_impl)), new Reference('doctrine.id_generator_locator')]);
        }
    }
}