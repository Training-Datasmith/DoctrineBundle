<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Mapping\Driver\Attribute_Driver;
use Doctrine\ORM\Mapping\Driver\Xml_Driver;
use Doctrine\Persistence\Mapping\Driver\Php_Driver;
use Doctrine\Persistence\Mapping\Driver\Static_Php_Driver;
use Doctrine\Persistence\Mapping\Driver\Symfony_File_Locator;
use function func_get_arg;
use function func_num_args;
use function gettype;
use function is_array;
use function is_bool;
use function sprintf;
use Symfony\Bridge\Doctrine\Dependency_Injection\Compiler_Pass\Register_Mappings_Pass;
use Symfony\Component\Dependency_Injection\Definition;
use Symfony\Component\Dependency_Injection\Reference;
use TypeError;
/**
 * Class for Symfony bundles to configure mappings for model classes not in the
 * auto-mapped folder.
 */
final class Doctrine_Orm_Mappings_Pass extends Register_Mappings_Pass
{
    /**
     * You should not directly instantiate this class but use one of the
     * factory methods.
     *
     * @param Definition|Reference $driver            Driver DI definition or reference.
     * @param string[]             $namespaces        List of namespaces handled by $driver.
     * @param string[]             $managerParameters Ordered list of container parameters that
     *                                                could hold the manager name.
     *                                                doctrine.default_entity_manager is appended
     *                                                automatically.
     * @param string|false         $enabledParameter  If specified, the compiler pass only executes
     *                                                if this parameter is defined in the service
     *                                                container.
     */
    public function __construct(Definition|Reference $driver, array $namespaces, array $manager_parameters, string|false $enabled_parameter = false)
    {
        $manager_parameters[] = 'doctrine.default_entity_manager';
        parent::__construct($driver, $namespaces, $manager_parameters, 'doctrine.orm.%s_metadata_driver', $enabled_parameter);
    }
    /**
     * @param string[]     $namespaces          Hashmap of directory path to namespace.
     * @param string[]     $managerParameters   List of parameters that could which object manager name
     *                                          your bundle uses. This compiler pass will automatically
     *                                          append the parameter name for the default entity manager
     *                                          to this list.
     * @param string|false $enabledParameter    Service container parameter that must be present to
     *                                          enable the mapping. Set to false to not do any check,
     *                                          optional.
     * @param bool         $enableXsdValidation
     *
     * @phpstan-ignore missingType.iterableValue
     */
    public static function create_xml_mapping_driver(array $namespaces, array $manager_parameters = [], string|false $enabled_parameter = false, bool|array $enable_xsd_validation = false): self
    {
        if (is_array($enable_xsd_validation)) {
            Deprecation::trigger('doctrine/doctrine-bundle', 'https://github.com/doctrine/DoctrineBundle/pull/2190', 'Providing a $aliasMap argument to %s is deprecated and has no effect.', __METHOD__);
            $enable_xsd_validation = false;
            if (func_num_args() === 5) {
                $enable_xsd_validation_arg = func_get_arg(4);
                if (!is_bool($enable_xsd_validation_arg)) {
                    throw new TypeError(sprintf('$enableXsdValidation is expected to be boolean, %s provided', gettype($enable_xsd_validation_arg)));
                }
                $enable_xsd_validation = $enable_xsd_validation_arg;
            }
        }
        $locator = new Definition(Symfony_File_Locator::class, [$namespaces, '.orm.xml']);
        $driver = new Definition(Xml_Driver::class, [$locator, Xml_Driver::DEFAULT_FILE_EXTENSION, $enable_xsd_validation]);
        return new Doctrine_Orm_Mappings_Pass($driver, $namespaces, $manager_parameters, $enabled_parameter);
    }
    /**
     * @param string[]     $namespaces        Hashmap of directory path to namespace
     * @param string[]     $managerParameters List of parameters that could which object manager name
     *                                        your bundle uses. This compiler pass will automatically
     *                                        append the parameter name for the default entity manager
     *                                        to this list.
     * @param string|false $enabledParameter  Service container parameter that must be present to
     *                                        enable the mapping. Set to false to not do any check,
     *                                        optional.
     */
    public static function create_php_mapping_driver(array $namespaces, array $manager_parameters = [], string|false $enabled_parameter = false): self
    {
        $locator = new Definition(Symfony_File_Locator::class, [$namespaces, '.php']);
        $driver = new Definition(Php_Driver::class, [$locator]);
        return new Doctrine_Orm_Mappings_Pass($driver, $namespaces, $manager_parameters, $enabled_parameter);
    }
    /**
     * @param string[]     $namespaces        List of namespaces that are handled with attribute mapping
     * @param string[]     $directories       List of directories to look for classes with attributes
     * @param string[]     $managerParameters List of parameters that could which object manager name
     *                                        your bundle uses. This compiler pass will automatically
     *                                        append the parameter name for the default entity manager
     *                                        to this list.
     * @param string|false $enabledParameter  Service container parameter that must be present to
     *                                        enable the mapping. Set to false to not do any check,
     *                                        optional.
     */
    public static function create_attribute_mapping_driver(array $namespaces, array $directories, array $manager_parameters = [], string|false $enabled_parameter = false): self
    {
        $driver = new Definition(Attribute_Driver::class, [$directories]);
        return new Doctrine_Orm_Mappings_Pass($driver, $namespaces, $manager_parameters, $enabled_parameter);
    }
    /**
     * @param string[]     $namespaces        List of namespaces that are handled with static php mapping
     * @param string[]     $directories       List of directories to look for static php mapping files
     * @param string[]     $managerParameters List of parameters that could which object manager name
     *                                        your bundle uses. This compiler pass will automatically
     *                                        append the parameter name for the default entity manager
     *                                        to this list.
     * @param string|false $enabledParameter  Service container parameter that must be present to
     *                                        enable the mapping. Set to false to not do any check,
     *                                        optional.
     */
    public static function create_static_php_mapping_driver(array $namespaces, array $directories, array $manager_parameters = [], string|false $enabled_parameter = false): self
    {
        $driver = new Definition(Static_Php_Driver::class, [$directories]);
        return new Doctrine_Orm_Mappings_Pass($driver, $namespaces, $manager_parameters, $enabled_parameter);
    }
}