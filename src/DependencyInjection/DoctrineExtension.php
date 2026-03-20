<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection;

use function array_flip;
use function array_keys;
use function array_merge;
use function array_replace;
use function array_values;
use function assert;
use function class_exists;
use function dirname;
use Doctrine\Bundle\Doctrine_Bundle\Attribute\As_Doctrine_Listener;
use Doctrine\Bundle\Doctrine_Bundle\Attribute\As_Entity_Listener;
use Doctrine\Bundle\Doctrine_Bundle\Attribute\As_Middleware;
use Doctrine\Bundle\Doctrine_Bundle\Cache_Warmer\Doctrine_Metadata_Cache_Warmer;
use Doctrine\Bundle\Doctrine_Bundle\Connection_Factory;
use Doctrine\Bundle\Doctrine_Bundle\Dbal\Manager_Registry_Aware_Connection_Provider;
use Doctrine\Bundle\Doctrine_Bundle\Dbal\Regex_Schema_Asset_Filter;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Id_Generator_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Service_Repository_Compiler_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Mapping\Container_Entity_Listener_Resolver;
use Doctrine\Bundle\Doctrine_Bundle\Repository\Service_Entity_Repository_Interface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\Primary_Read_Replica_Connection;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\ORM\Cache\Cache_Configuration;
use Doctrine\ORM\Cache\Default_Cache_Factory;
use Doctrine\ORM\Cache\Logging\Cache_Logger_Chain;
use Doctrine\ORM\Cache\Logging\Statistics_Cache_Logger;
use Doctrine\ORM\Cache\Region\Default_Region;
use Doctrine\ORM\Cache\Region\File_Lock_Region;
use Doctrine\ORM\Cache\Regions_Configuration;
use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Id\Abstract_Id_Generator;
use Doctrine\ORM\Mapping\Driver\Attribute_Driver;
use Doctrine\ORM\Mapping\Driver\Simplified_Xml_Driver;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Mapped_Superclass;
use Doctrine\ORM\Proxy\Autoloader;
use Doctrine\ORM\Tools\Attach_Entity_Listeners_Listener;
use Doctrine\ORM\Tools\Console\Command\Debug\Debug_Event_Manager_Doctrine_Command;
use Doctrine\ORM\Unit_Of_Work;
use Doctrine\Persistence\Mapping\Driver\Mapping_Driver_Chain;
use Doctrine\Persistence\Mapping\Driver\Php_Driver;
use Doctrine\Persistence\Mapping\Driver\Static_Php_Driver;
use function glob;
use const GLOB_NOSORT;
use function in_array;
use function interface_exists;
use InvalidArgumentException;
use function is_dir;
use function is_string;
use LogicException;
use function realpath;
use ReflectionClass;
use function reset;
use function sprintf;
use function str_replace;
use Symfony\Bridge\Doctrine\Attribute\Map_Entity;
use Symfony\Bridge\Doctrine\Id_Generator\Ulid_Generator;
use Symfony\Bridge\Doctrine\Id_Generator\Uuid_Generator;
use Symfony\Bridge\Doctrine\Middleware\Idle_Connection\Listener;
use Symfony\Bridge\Doctrine\Property_Info\Doctrine_Extractor;
use Symfony\Bridge\Doctrine\Validator\Doctrine_Loader;
use Symfony\Component\Cache\Adapter\Array_Adapter;
use Symfony\Component\Cache\Adapter\Php_Array_Adapter;
use Symfony\Component\Config\Definition\Configuration_Interface;
use Symfony\Component\Config\File_Locator;
use Symfony\Component\Dependency_Injection\Alias;
use Symfony\Component\Dependency_Injection\Child_Definition;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Dependency_Injection\Definition;
use Symfony\Component\Dependency_Injection\Extension\Extension;
use Symfony\Component\Dependency_Injection\Loader\Php_File_Loader;
use Symfony\Component\Dependency_Injection\Reference;
use Symfony\Component\Expression_Language\Expression_Language;
use Symfony\Component\Form\Abstract_Type;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Doctrine_Transport_Factory;
use Symfony\Component\Messenger\Message_Bus_Interface;
use Symfony\Component\Property_Info\Property_Info_Extractor_Interface;
use Symfony\Component\Validator\Mapping\Loader\Loader_Interface;
/**
 * DoctrineExtension is an extension for the Doctrine DBAL and ORM library.
 *
 * @internal
 *
 * @phpstan-type DBALConfig = array{
 *      connections: array<string, array{logging: bool, profiling: bool, profiling_collect_backtrace: bool, idle_connection_ttl: int}>,
 *      driver_schemes: array<string, string>,
 *      default_connection: string,
 *      types: array<string, string>,
 *  }
 */
final class Doctrine_Extension extends Extension
{
    /**
     * Used inside metadata driver method to simplify aggregation of data.
     *
     * @var array<string, string> List of alias => namespace
     */
    private array $alias_map = [];
    /**
     * Used inside metadata driver method to simplify aggregation of data.
     *
     * @var array<string, array<string, string>> List of driver type => prefix => path
     */
    private array $drivers = [];
    /**
     * @param array<string, mixed> $objectManager A configured object manager
     *
     * @throws InvalidArgumentException
     */
    private function load_mapping_information(array $object_manager, Container_Builder $container): void
    {
        if ($object_manager['auto_mapping']) {
            // automatically register bundle mappings
            $bundles = $container->get_parameter('kernel.bundles');
            foreach (array_keys($bundles) as $bundle) {
                if (isset($object_manager['mappings'][$bundle])) {
                    continue;
                }
                $object_manager['mappings'][$bundle] = ['mapping' => true, 'is_bundle' => true];
            }
        }
        foreach ($object_manager['mappings'] as $mapping_name => $mapping_config) {
            if ($mapping_config !== null && $mapping_config['mapping'] === false) {
                continue;
            }
            $mapping_config = array_replace(['dir' => false, 'type' => false, 'prefix' => false], (array) $mapping_config);
            $mapping_config['dir'] = $container->get_parameter_bag()->resolve_value($mapping_config['dir']);
            // a bundle configuration is detected by realizing that the specified dir is not absolute and existing
            if (!isset($mapping_config['is_bundle'])) {
                $mapping_config['is_bundle'] = !is_dir((string) $mapping_config['dir']);
            }
            if ($mapping_config['is_bundle']) {
                $bundle = null;
                $bundle_metadata = null;
                /** @var array<string, class-string> $kernelBundles */
                $kernel_bundles = $container->get_parameter('kernel.bundles');
                $kernel_bundles_metadata = $container->get_parameter('kernel.bundles_metadata');
                foreach ($kernel_bundles as $name => $class) {
                    if ($mapping_name === $name) {
                        $bundle = new ReflectionClass($class);
                        $bundle_metadata = $kernel_bundles_metadata[$name];
                        break;
                    }
                }
                if ($bundle === null) {
                    throw new InvalidArgumentException(sprintf('Bundle "%s" does not exist or it is not enabled.', $mapping_name));
                }
                if ($bundle_metadata !== null) {
                    $mapping_config = $this->get_mapping_driver_bundle_config_defaults($mapping_config, $bundle, $container, $bundle_metadata['path']);
                    if (!$mapping_config) {
                        continue;
                    }
                }
            } elseif (!$mapping_config['type']) {
                $mapping_config['type'] = 'attribute';
            }
            $this->assert_valid_mapping_configuration($mapping_config, $object_manager['name']);
            $this->set_mapping_driver_config($mapping_config, $mapping_name);
            $this->set_mapping_driver_alias($mapping_config, $mapping_name);
        }
    }
    /**
     * Register the alias for this mapping driver.
     *
     * Aliases can be used in the Query languages of all the Doctrine object managers to simplify writing tasks.
     *
     * @param array<string, mixed> $mappingConfig
     */
    private function set_mapping_driver_alias(array $mapping_config, string $mapping_name): void
    {
        if (isset($mapping_config['alias'])) {
            $this->alias_map[$mapping_config['alias']] = $mapping_config['prefix'];
        } else {
            $this->alias_map[$mapping_name] = $mapping_config['prefix'];
        }
    }
    /**
     * Register the mapping driver configuration for later use with the object managers metadata driver chain.
     *
     * @param array<string, mixed> $mappingConfig
     *
     * @throws InvalidArgumentException
     */
    private function set_mapping_driver_config(array $mapping_config, string $mapping_name): void
    {
        $mapping_directory = $mapping_config['dir'];
        if (!is_dir($mapping_directory)) {
            throw new InvalidArgumentException(sprintf('Invalid Doctrine mapping path given. Cannot load Doctrine mapping/bundle named "%s".', $mapping_name));
        }
        $this->drivers[$mapping_config['type']][$mapping_config['prefix']] = realpath($mapping_directory) ?: $mapping_directory;
    }
    /**
     * If this is a bundle controlled mapping all the missing information can be autodetected by this method.
     *
     * Returns false when autodetection failed, an array of the completed information otherwise.
     *
     * @param array<string, mixed>    $bundleConfig
     * @param ReflectionClass<object> $bundle
     *
     * @return array<string, mixed>|false
     */
    private function get_mapping_driver_bundle_config_defaults(array $bundle_config, ReflectionClass $bundle, Container_Builder $container, string|null $bundle_dir = null): array|false
    {
        $file_name = $bundle->get_file_name();
        assert(is_string($file_name));
        $bundle_class_dir = dirname($file_name);
        $bundle_dir ??= $bundle_class_dir;
        if (!$bundle_config['type']) {
            $bundle_config['type'] = $this->detect_metadata_driver($bundle_dir, $container);
            if (!$bundle_config['type'] && $bundle_dir !== $bundle_class_dir) {
                $bundle_config['type'] = $this->detect_metadata_driver($bundle_class_dir, $container);
            }
        }
        if (!$bundle_config['type']) {
            // skip this bundle, no mapping information was found.
            return false;
        }
        if (!$bundle_config['dir']) {
            if (in_array($bundle_config['type'], ['staticphp', 'attribute'])) {
                $bundle_config['dir'] = $bundle_class_dir . '/' . $this->get_mapping_object_default_name();
            } else {
                $bundle_config['dir'] = $bundle_dir . '/' . $this->get_mapping_resource_config_directory($bundle_dir);
            }
        } else {
            $bundle_config['dir'] = $bundle_dir . '/' . $bundle_config['dir'];
        }
        if (!$bundle_config['prefix']) {
            $bundle_config['prefix'] = $bundle->get_namespace_name() . '\\' . $this->get_mapping_object_default_name();
        }
        return $bundle_config;
    }
    /**
     * Register all the collected mapping information with the object manager by registering the appropriate mapping drivers.
     *
     * @param array<string, mixed> $objectManager
     */
    private function register_mapping_drivers(array $object_manager, Container_Builder $container): void
    {
        // configure metadata driver for each bundle based on the type of mapping files found
        if ($container->has_definition($this->get_object_manager_element_name($object_manager['name'] . '_metadata_driver'))) {
            $chain_driver_def = $container->get_definition($this->get_object_manager_element_name($object_manager['name'] . '_metadata_driver'));
        } else {
            $chain_driver_def = new Definition($this->get_metadata_driver_class('driver_chain'));
        }
        foreach ($this->drivers as $driver_type => $driver_paths) {
            $mapping_service = $this->get_object_manager_element_name($object_manager['name'] . '_' . $driver_type . '_metadata_driver');
            $mapping_driver_def = new Definition($this->get_metadata_driver_class($driver_type), [array_values($driver_paths)]);
            if ($mapping_driver_def->get_class() === Simplified_Xml_Driver::class) {
                $mapping_driver_def->set_arguments([array_flip($driver_paths)]);
                $mapping_driver_def->add_method_call('setGlobalBasename', ['mapping']);
            }
            $container->set_definition($mapping_service, $mapping_driver_def);
            foreach ($driver_paths as $prefix => $driver_path) {
                $chain_driver_def->add_method_call('addDriver', [new Reference($mapping_service), $prefix]);
            }
        }
        $container->set_definition($this->get_object_manager_element_name($object_manager['name'] . '_metadata_driver'), $chain_driver_def);
    }
    /**
     * Assertion if the specified mapping information is valid.
     *
     * @param array<string, mixed> $mappingConfig
     *
     * @throws InvalidArgumentException
     */
    private function assert_valid_mapping_configuration(array $mapping_config, string $object_manager_name): void
    {
        if (!$mapping_config['type'] || !$mapping_config['dir'] || !$mapping_config['prefix']) {
            throw new InvalidArgumentException(sprintf('Mapping definitions for Doctrine manager "%s" require at least the "type", "dir" and "prefix" options.', $object_manager_name));
        }
        if (!is_dir($mapping_config['dir'])) {
            throw new InvalidArgumentException(sprintf('Specified non-existing directory "%s" as Doctrine mapping source.', $mapping_config['dir']));
        }
        if (!in_array($mapping_config['type'], ['xml', 'php', 'staticphp', 'attribute'])) {
            throw new InvalidArgumentException(sprintf('Can only configure "xml", "php", "staticphp" or "attribute" through the DoctrineBundle. Use your own bundle to configure other metadata drivers. You can register them by adding a new driver to the "%s" service definition.', $this->get_object_manager_element_name($object_manager_name . '_metadata_driver')));
        }
    }
    /**
     * Detects what metadata driver to use for the supplied directory.
     */
    private function detect_metadata_driver(string $dir, Container_Builder $container): string|null
    {
        $config_path = $this->get_mapping_resource_config_directory($dir);
        $extension = $this->get_mapping_resource_extension();
        if (glob($dir . '/' . $config_path . '/*.' . $extension . '.xml', GLOB_NOSORT)) {
            $driver = 'xml';
        } elseif (glob($dir . '/' . $config_path . '/*.' . $extension . '.yml', GLOB_NOSORT)) {
            $driver = 'yml';
        } elseif (glob($dir . '/' . $config_path . '/*.' . $extension . '.php', GLOB_NOSORT)) {
            $driver = 'php';
        } else {
            // add the closest existing directory as a resource
            $resource = $dir . '/' . $config_path;
            while (!is_dir($resource)) {
                $resource = dirname($resource);
            }
            $container->file_exists($resource, false);
            if ($container->file_exists($dir . '/' . $this->get_mapping_object_default_name(), false)) {
                return 'attribute';
            }
            return null;
        }
        $container->file_exists($dir . '/' . $config_path, false);
        return $driver;
    }
    /**
     * Returns a modified version of $managerConfigs.
     *
     * The manager called $autoMappedManager will map all bundles that are not mapped by other managers.
     *
     * @param array<string, array<string, mixed>> $managerConfigs
     * @param array<string, string>               $bundles
     *
     * @return array<string, array<string, mixed>>
     */
    private function fix_managers_auto_mappings(array $manager_configs, array $bundles): array
    {
        $auto_mapped_manager = $this->validate_auto_mapping($manager_configs);
        if ($auto_mapped_manager !== null) {
            foreach (array_keys($bundles) as $bundle) {
                foreach ($manager_configs as $manager) {
                    if (isset($manager['mappings'][$bundle])) {
                        continue 2;
                    }
                }
                $manager_configs[$auto_mapped_manager]['mappings'][$bundle] = ['mapping' => true, 'is_bundle' => true];
            }
            $manager_configs[$auto_mapped_manager]['auto_mapping'] = false;
        }
        return $manager_configs;
    }
    /**
     * Search for a manager that is declared as 'auto_mapping' = true.
     *
     * @param array<string, array<string, mixed>> $managerConfigs
     *
     * @throws LogicException
     */
    private function validate_auto_mapping(array $manager_configs): string|null
    {
        $auto_mapped_manager = null;
        foreach ($manager_configs as $name => $manager) {
            if (!$manager['auto_mapping']) {
                continue;
            }
            if ($auto_mapped_manager !== null) {
                throw new LogicException(sprintf('You cannot enable "auto_mapping" on more than one manager at the same time (found in "%s" and "%s"").', $auto_mapped_manager, $name));
            }
            $auto_mapped_manager = $name;
        }
        return $auto_mapped_manager;
    }
    private string $default_connection;
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, Container_Builder $container): void
    {
        $configuration = $this->get_configuration($configs, $container);
        $config = $this->process_configuration_prepending_defaults($configuration, $configs);
        if (!empty($config['dbal'])) {
            $this->dbal_load($config['dbal'], $container);
            $this->load_messenger_services($container);
        }
        if (empty($config['orm'])) {
            return;
        }
        if (empty($config['dbal'])) {
            throw new LogicException('Configuring the ORM layer requires to configure the DBAL layer as well.');
        }
        $this->orm_load($config['orm'], $container);
    }
    /**
     * Process user configuration and adds a default DBAL connection and/or a
     * default EM if required, then process again the configuration to get
     * default values for each.
     *
     * @param array<array<mixed>> $configs
     *
     * @return array<mixed>
     */
    private function process_configuration_prepending_defaults(Configuration_Interface $configuration, array $configs): array
    {
        $config = $this->process_configuration($configuration, $configs);
        $config_to_add = [];
        // if no DB connection defined, prepend an empty one for the default
        // connection name in order to make Symfony Config resolve the default
        // values
        if (isset($config['dbal']) && empty($config['dbal']['connections'])) {
            $config_to_add['dbal'] = ['connections' => [$config['dbal']['default_connection'] ?? 'default' => []]];
        }
        // if no EM defined, prepend an empty one for the default EM name in
        // order to make Symfony Config resolve the default values
        if (isset($config['orm']) && empty($config['orm']['entity_managers'])) {
            $config_to_add['orm'] = ['entity_managers' => [$config['orm']['default_entity_manager'] ?? 'default' => []]];
        }
        if (!$config_to_add) {
            return $config;
        }
        return $this->process_configuration($configuration, array_merge([$config_to_add], $configs));
    }
    /**
     * Loads the DBAL configuration.
     *
     * Usage example:
     *
     *      <doctrine:dbal id="myconn" dbname="sfweb" user="root" />
     *
     * @param DBALConfig       $config    An array of configuration settings
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    private function dbal_load(array $config, Container_Builder $container): void
    {
        $loader = new Php_File_Loader($container, new File_Locator(__DIR__ . '/../../config'));
        $loader->load('dbal.php');
        if (empty($config['default_connection'])) {
            $keys = array_keys($config['connections']);
            $config['default_connection'] = reset($keys);
        }
        assert(is_string($config['default_connection']));
        $this->default_connection = $config['default_connection'];
        $container->set_alias('database_connection', sprintf('doctrine.dbal.%s_connection', $this->default_connection));
        $container->get_alias('database_connection')->set_public(true);
        $container->set_alias('doctrine.dbal.event_manager', new Alias(sprintf('doctrine.dbal.%s_connection.event_manager', $this->default_connection), false));
        $container->set_parameter('doctrine.dbal.connection_factory.types', $config['types']);
        $container->get_definition('doctrine.dbal.connection_factory.dsn_parser')->set_argument(0, array_merge(Connection_Factory::DEFAULT_SCHEME_MAP, $config['driver_schemes']));
        $connections = [];
        foreach (array_keys($config['connections']) as $name) {
            $connections[$name] = sprintf('doctrine.dbal.%s_connection', $name);
        }
        $container->set_parameter('doctrine.connections', $connections);
        $container->set_parameter('doctrine.default_connection', $this->default_connection);
        $conn_with_logging = [];
        $conn_with_profiling = [];
        $conn_with_backtrace = [];
        $ttl_by_connection = [];
        foreach ($config['connections'] as $name => $connection) {
            if ($connection['logging']) {
                $conn_with_logging[] = $name;
            }
            if ($connection['profiling']) {
                $conn_with_profiling[] = $name;
                if ($connection['profiling_collect_backtrace']) {
                    $conn_with_backtrace[] = $name;
                }
            }
            if ($connection['idle_connection_ttl'] > 0) {
                $ttl_by_connection[$name] = $connection['idle_connection_ttl'];
            }
            $this->load_dbal_connection($name, $connection, $container);
        }
        $container->register_for_autoconfiguration(Middleware_Interface::class)->add_tag('doctrine.middleware');
        $container->register_attribute_for_autoconfiguration(As_Middleware::class, static function (Child_Definition $definition, As_Middleware $attribute): void {
            $priority = isset($attribute->priority) ? ['priority' => $attribute->priority] : [];
            if ($attribute->connections === []) {
                $definition->add_tag('doctrine.middleware', $priority);
                return;
            }
            foreach ($attribute->connections as $conn_name) {
                $definition->add_tag('doctrine.middleware', array_merge($priority, ['connection' => $conn_name]));
            }
        });
        $this->register_dbal_middlewares($container, $conn_with_logging, $conn_with_profiling, $conn_with_backtrace, array_keys($ttl_by_connection));
        $container->get_definition('doctrine.dbal.idle_connection_middleware')->set_argument(1, $ttl_by_connection);
        if (class_exists(Listener::class)) {
            return;
        }
        $container->remove_definition('doctrine.dbal.idle_connection_listener');
        $container->remove_definition('doctrine.dbal.idle_connection_middleware');
    }
    /**
     * Loads a configured DBAL connection.
     *
     * @param string               $name       The name of the connection
     * @param array<string, mixed> $connection A dbal connection configuration.
     * @param ContainerBuilder     $container  A ContainerBuilder instance
     */
    private function load_dbal_connection(string $name, array $connection, Container_Builder $container): void
    {
        $configuration = $container->set_definition(sprintf('doctrine.dbal.%s_connection.configuration', $name), new Child_Definition('doctrine.dbal.connection.configuration'));
        unset($connection['logging']);
        $data_collector_definition = $container->get_definition('data_collector.doctrine');
        $data_collector_definition->replace_argument(1, $connection['profiling_collect_schema_errors']);
        unset($connection['profiling'], $connection['profiling_collect_backtrace'], $connection['profiling_collect_schema_errors']);
        if (isset($connection['auto_commit'])) {
            $configuration->add_method_call('setAutoCommit', [$connection['auto_commit']]);
        }
        unset($connection['auto_commit']);
        if (isset($connection['schema_filter']) && $connection['schema_filter']) {
            $definition = new Definition(Regex_Schema_Asset_Filter::class, [$connection['schema_filter']]);
            $definition->add_tag('doctrine.dbal.schema_filter', ['connection' => $name]);
            $container->set_definition(sprintf('doctrine.dbal.%s_regex_schema_filter', $name), $definition);
        }
        unset($connection['schema_filter']);
        // event manager
        $container->set_definition(sprintf('doctrine.dbal.%s_connection.event_manager', $name), new Child_Definition('doctrine.dbal.connection.event_manager'));
        // connection
        $options = $this->get_connection_options($connection);
        $connection_id = sprintf('doctrine.dbal.%s_connection', $name);
        $def = $container->set_definition($connection_id, new Child_Definition('doctrine.dbal.connection'))->set_public(true)->set_arguments([$options, new Reference(sprintf('doctrine.dbal.%s_connection.configuration', $name)), $connection['mapping_types']]);
        $container->register_alias_for_argument($connection_id, Connection::class, sprintf('%s.connection', $name))->set_public(false);
        // Set class in case "wrapper_class" option was used to assist IDEs
        if (isset($options['wrapperClass'])) {
            $def->set_class($options['wrapperClass']);
        }
        $container->set_definition(Manager_Registry_Aware_Connection_Provider::class, new Definition(Manager_Registry_Aware_Connection_Provider::class, [$container->get_definition('doctrine')]));
        $configuration->add_method_call('setSchemaManagerFactory', [new Reference($connection['schema_manager_factory'])]);
        if (!isset($connection['result_cache'])) {
            return;
        }
        $configuration->add_method_call('setResultCache', [new Reference($connection['result_cache'])]);
    }
    /**
     * @param array<string, mixed> $connection
     *
     * @return mixed[]
     */
    private function get_connection_options(array $connection): array
    {
        $options = $connection;
        $connection_defaults = ['host' => 'localhost', 'port' => null, 'user' => 'root', 'password' => null];
        unset($options['schema_manager_factory']);
        $options += $connection_defaults;
        foreach (array_keys($options['replicas']) as $name) {
            $options['replicas'][$name] += $connection_defaults;
        }
        unset($options['mapping_types']);
        foreach (['options' => 'driverOptions', 'driver_class' => 'driverClass', 'wrapper_class' => 'wrapperClass', 'keep_replica' => 'keepReplica', 'replicas' => 'replica', 'server_version' => 'serverVersion', 'default_table_options' => 'defaultTableOptions'] as $old => $new) {
            if (!isset($options[$old])) {
                continue;
            }
            $options[$new] = $options[$old];
            unset($options[$old]);
        }
        foreach ($options['replica'] as $name => $value) {
            $driver_options = $value['driverOptions'] ?? [];
            $parent_driver_options = $options['driverOptions'] ?? [];
            if ($driver_options === [] && $parent_driver_options === []) {
                continue;
            }
            $options['replica'][$name]['driverOptions'] = $driver_options + $parent_driver_options;
        }
        if (!empty($options['replica'])) {
            $non_rewritten_keys = [
                'driver' => true,
                'driverClass' => true,
                'wrapperClass' => true,
                'keepReplica' => true,
                'platform' => true,
                'primary' => true,
                'replica' => true,
                'serverVersion' => true,
                'defaultTableOptions' => true,
                // included by safety but should have been unset already
                'logging' => true,
                'profiling' => true,
                'mapping_types' => true,
                'platform_service' => true,
            ];
            foreach ($options as $key => $value) {
                if (isset($non_rewritten_keys[$key])) {
                    continue;
                }
                $options['primary'][$key] = $value;
                unset($options[$key]);
            }
            if (empty($options['wrapperClass'])) {
                // Change the wrapper class only if user did not configure custom one.
                $options['wrapperClass'] = Primary_Read_Replica_Connection::class;
            }
        } else {
            unset($options['replica']);
        }
        return $options;
    }
    /**
     * Loads the Doctrine ORM configuration.
     *
     * Usage example:
     *
     *     <doctrine:orm id="mydm" connection="myconn" />
     *
     * @param array<string, mixed> $config    An array of configuration settings
     * @param ContainerBuilder     $container A ContainerBuilder instance
     */
    private function orm_load(array $config, Container_Builder $container): void
    {
        if (!class_exists(Unit_Of_Work::class)) {
            throw new LogicException('To configure the ORM layer, you must first install the doctrine/orm package.');
        }
        $loader = new Php_File_Loader($container, new File_Locator(__DIR__ . '/../../config'));
        $loader->load('orm.php');
        if (class_exists(Abstract_Type::class)) {
            $container->get_definition('form.type.entity')->add_tag('kernel.reset', ['method' => 'reset']);
        }
        if (!class_exists(Ulid_Generator::class)) {
            $container->remove_definition('doctrine.ulid_generator');
        }
        if (!class_exists(Uuid_Generator::class)) {
            $container->remove_definition('doctrine.uuid_generator');
        }
        if (!class_exists(Expression_Language::class)) {
            $container->remove_definition('doctrine.orm.entity_value_resolver.expression_language');
        }
        if (!class_exists(Debug_Event_Manager_Doctrine_Command::class)) {
            $container->remove_definition('doctrine.event_manager_debug_command');
            $container->remove_definition('doctrine.entity_listeners_debug_command');
        }
        $controller_resolver_defaults = [];
        if (!$config['controller_resolver']['enabled']) {
            $controller_resolver_defaults['disabled'] = true;
        }
        if ($config['controller_resolver']['evict_cache']) {
            $controller_resolver_defaults['evict_cache'] = true;
        }
        $value_resolver_definition = $container->get_definition('doctrine.orm.entity_value_resolver');
        $value_resolver_definition->set_argument(2, (new Definition(Map_Entity::class))->set_arguments([null, null, null, null, null, null, null, $controller_resolver_defaults['evict_cache'] ?? null, $controller_resolver_defaults['disabled'] ?? false]));
        // Symfony 7.3 and higher expose type alias support in the EntityValueResolver
        $value_resolver_definition->set_argument(3, $config['resolve_target_entities']);
        $entity_managers = [];
        foreach (array_keys($config['entity_managers']) as $name) {
            $entity_managers[$name] = sprintf('doctrine.orm.%s_entity_manager', $name);
        }
        $container->set_parameter('doctrine.entity_managers', $entity_managers);
        if (empty($config['default_entity_manager'])) {
            $tmp = array_keys($entity_managers);
            $config['default_entity_manager'] = reset($tmp);
        }
        $container->set_parameter('doctrine.default_entity_manager', $config['default_entity_manager']);
        $container->set_alias('doctrine.orm.entity_manager', $default_entity_manager_definition_id = sprintf('doctrine.orm.%s_entity_manager', $config['default_entity_manager']));
        $container->get_alias('doctrine.orm.entity_manager')->set_public(true);
        $bundles = $container->get_parameter('kernel.bundles');
        $config['entity_managers'] = $this->fix_managers_auto_mappings($config['entity_managers'], $bundles);
        foreach ($config['entity_managers'] as $name => $entity_manager) {
            $entity_manager['name'] = $name;
            $this->load_orm_entity_manager($entity_manager, $container);
            if (interface_exists(Property_Info_Extractor_Interface::class)) {
                $this->load_property_info_extractor($name, $container);
            }
            if (!interface_exists(Loader_Interface::class)) {
                continue;
            }
            $this->load_validator_loader($name, $container);
        }
        if ($config['resolve_target_entities']) {
            $def = $container->find_definition('doctrine.orm.listeners.resolve_target_entity');
            foreach ($config['resolve_target_entities'] as $name => $implementation) {
                $def->add_method_call('addResolveTargetEntity', [$name, $implementation, []]);
            }
            $def->add_tag('doctrine.event_listener', ['event' => Events::loadClassMetadata])->add_tag('doctrine.event_listener', ['event' => Events::onClassMetadataNotFound]);
        }
        $container->register_for_autoconfiguration(Service_Entity_Repository_Interface::class)->add_tag(Service_Repository_Compiler_Pass::REPOSITORY_SERVICE_TAG);
        $container->register_for_autoconfiguration(Abstract_Id_Generator::class)->add_tag(Id_Generator_Pass::ID_GENERATOR_TAG);
        $container->register_attribute_for_autoconfiguration(As_Entity_Listener::class, static function (Child_Definition $definition, As_Entity_Listener $attribute): void {
            $definition->add_tag('doctrine.orm.entity_listener', ['event' => $attribute->event, 'method' => $attribute->method, 'lazy' => $attribute->lazy, 'entity_manager' => $attribute->entity_manager, 'entity' => $attribute->entity, 'priority' => $attribute->priority]);
        });
        $container->register_attribute_for_autoconfiguration(As_Doctrine_Listener::class, static function (Child_Definition $definition, As_Doctrine_Listener $attribute): void {
            $definition->add_tag('doctrine.event_listener', ['event' => $attribute->event, 'priority' => $attribute->priority, 'connection' => $attribute->connection]);
        });
        $container->register_attribute_for_autoconfiguration(Embeddable::class, static function (Child_Definition $definition): void {
            $definition->set_abstract(true)->add_tag('container.excluded', ['source' => sprintf('with #[%s] attribute', Embeddable::class)]);
        });
        $container->register_attribute_for_autoconfiguration(Entity::class, static function (Child_Definition $definition): void {
            $definition->set_abstract(true)->add_tag('container.excluded', ['source' => sprintf('with #[%s] attribute', Entity::class)]);
        });
        $container->register_attribute_for_autoconfiguration(Mapped_Superclass::class, static function (Child_Definition $definition): void {
            $definition->set_abstract(true)->add_tag('container.excluded', ['source' => sprintf('with #[%s] attribute', Mapped_Superclass::class)]);
        });
        /** @see DoctrineBundle::boot() */
        $container->get_definition($default_entity_manager_definition_id)->add_tag('container.preload', ['class' => Autoloader::class]);
    }
    /**
     * Loads a configured ORM entity manager.
     *
     * @param array<string, mixed> $entityManager A configured ORM entity manager.
     * @param ContainerBuilder     $container     A ContainerBuilder instance
     */
    private function load_orm_entity_manager(array $entity_manager, Container_Builder $container): void
    {
        $orm_config_def = $container->set_definition(sprintf('doctrine.orm.%s_configuration', $entity_manager['name']), new Child_Definition('doctrine.orm.configuration'));
        $orm_config_def->add_tag(Id_Generator_Pass::CONFIGURATION_TAG);
        $this->load_orm_entity_manager_mapping_information($entity_manager, $orm_config_def, $container);
        $this->load_orm_cache_drivers($entity_manager, $container);
        if (isset($entity_manager['entity_listener_resolver']) && $entity_manager['entity_listener_resolver']) {
            $container->set_alias(sprintf('doctrine.orm.%s_entity_listener_resolver', $entity_manager['name']), $entity_manager['entity_listener_resolver']);
        } else {
            $definition = new Definition(Container_Entity_Listener_Resolver::class);
            $definition->add_argument(new Reference('service_container'));
            $container->set_definition(sprintf('doctrine.orm.%s_entity_listener_resolver', $entity_manager['name']), $definition);
        }
        $methods = ['enableNativeLazyObjects' => true, 'setMetadataCache' => new Reference(sprintf('doctrine.orm.%s_metadata_cache', $entity_manager['name'])), 'setQueryCache' => new Reference(sprintf('doctrine.orm.%s_query_cache', $entity_manager['name'])), 'setResultCache' => new Reference(sprintf('doctrine.orm.%s_result_cache', $entity_manager['name'])), 'setMetadataDriverImpl' => new Reference('doctrine.orm.' . $entity_manager['name'] . '_metadata_driver'), 'setSchemaIgnoreClasses' => $entity_manager['schema_ignore_classes'], 'setClassMetadataFactoryName' => $entity_manager['class_metadata_factory_name'], 'setDefaultRepositoryClassName' => $entity_manager['default_repository_class'], 'setNamingStrategy' => new Reference($entity_manager['naming_strategy']), 'setQuoteStrategy' => new Reference($entity_manager['quote_strategy']), 'setTypedFieldMapper' => new Reference($entity_manager['typed_field_mapper']), 'setEntityListenerResolver' => new Reference(sprintf('doctrine.orm.%s_entity_listener_resolver', $entity_manager['name'])), 'setIdentityGenerationPreferences' => $entity_manager['identity_generation_preferences']];
        if (isset($entity_manager['fetch_mode_subselect_batch_size'])) {
            $methods['setEagerFetchBatchSize'] = $entity_manager['fetch_mode_subselect_batch_size'];
        }
        $listener_id = sprintf('doctrine.orm.%s_listeners.attach_entity_listeners', $entity_manager['name']);
        $listener_def = $container->set_definition($listener_id, new Definition(Attach_Entity_Listeners_Listener::class));
        $listener_tag_params = ['event' => 'loadClassMetadata'];
        if (isset($entity_manager['connection'])) {
            $listener_tag_params['connection'] = $entity_manager['connection'];
        }
        $listener_def->add_tag('doctrine.event_listener', $listener_tag_params);
        if (isset($entity_manager['second_level_cache'])) {
            $this->load_orm_second_level_cache($entity_manager, $orm_config_def, $container);
        }
        if ($entity_manager['repository_factory']) {
            $methods['setRepositoryFactory'] = new Reference($entity_manager['repository_factory']);
        }
        foreach ($methods as $method => $arg) {
            $orm_config_def->add_method_call($method, [$arg]);
        }
        foreach ($entity_manager['hydrators'] as $name => $class) {
            $orm_config_def->add_method_call('addCustomHydrationMode', [$name, $class]);
        }
        if (!empty($entity_manager['dql'])) {
            foreach ($entity_manager['dql']['string_functions'] as $name => $function) {
                $orm_config_def->add_method_call('addCustomStringFunction', [$name, $function]);
            }
            foreach ($entity_manager['dql']['numeric_functions'] as $name => $function) {
                $orm_config_def->add_method_call('addCustomNumericFunction', [$name, $function]);
            }
            foreach ($entity_manager['dql']['datetime_functions'] as $name => $function) {
                $orm_config_def->add_method_call('addCustomDatetimeFunction', [$name, $function]);
            }
        }
        $enabled_filters = [];
        $filters_parameters = [];
        foreach ($entity_manager['filters'] as $name => $filter) {
            $orm_config_def->add_method_call('addFilter', [$name, $filter['class']]);
            if ($filter['enabled']) {
                $enabled_filters[] = $name;
            }
            if (!$filter['parameters']) {
                continue;
            }
            $filters_parameters[$name] = $filter['parameters'];
        }
        $manager_configurator_name = sprintf('doctrine.orm.%s_manager_configurator', $entity_manager['name']);
        $container->set_definition($manager_configurator_name, new Child_Definition('doctrine.orm.manager_configurator.abstract'))->replace_argument(0, $enabled_filters)->replace_argument(1, $filters_parameters);
        if (!isset($entity_manager['connection'])) {
            $entity_manager['connection'] = $this->default_connection;
        }
        $entity_manager_id = sprintf('doctrine.orm.%s_entity_manager', $entity_manager['name']);
        $container->set_definition($entity_manager_id, new Child_Definition('doctrine.orm.entity_manager.abstract'))->set_public(true)->set_arguments([new Reference(sprintf('doctrine.dbal.%s_connection', $entity_manager['connection'])), new Reference(sprintf('doctrine.orm.%s_configuration', $entity_manager['name'])), new Reference(sprintf('doctrine.dbal.%s_connection.event_manager', $entity_manager['connection']))])->set_configurator([new Reference($manager_configurator_name), 'configure']);
        $container->register_alias_for_argument($entity_manager_id, Entity_Manager_Interface::class, sprintf('%s.entity_manager', $entity_manager['name']))->set_public(false);
        $container->set_alias(sprintf('doctrine.orm.%s_entity_manager.event_manager', $entity_manager['name']), new Alias(sprintf('doctrine.dbal.%s_connection.event_manager', $entity_manager['connection']), false));
        if (!isset($entity_manager['entity_listeners'])) {
            return;
        }
        $entities = $entity_manager['entity_listeners']['entities'];
        foreach ($entities as $entity_listener_class => $entity) {
            foreach ($entity['listeners'] as $listener_class => $listener) {
                foreach ($listener['events'] as $listener_event) {
                    $listener_event_name = $listener_event['type'];
                    $listener_method = $listener_event['method'];
                    $listener_def->add_method_call('addEntityListener', [$entity_listener_class, $listener_class, $listener_event_name, $listener_method]);
                }
            }
        }
    }
    /**
     * Loads an ORM entity managers bundle mapping information.
     *
     * There are two distinct configuration possibilities for mapping information:
     *
     * 1. Specify a bundle and optionally details where the entity and mapping information reside.
     * 2. Specify an arbitrary mapping location.
     *
     * @param array<string, mixed> $entityManager A configured ORM entity manager
     * @param Definition           $ormConfigDef  A Definition instance
     * @param ContainerBuilder     $container     A ContainerBuilder instance
     *
     * @example
     *
     *  doctrine.orm:
     *     mappings:
     *         MyBundle1: ~
     *         MyBundle2: xml
     *         MyBundle3: { type: xml, dir: Resources/config/doctrine/mapping }
     *         MyBundle4: { type: attribute, dir: Entities/ }
     *         MyBundle5:
     *             type: xml
     *             dir: bundle-mappings/
     *             alias: BundleAlias
     *         arbitrary_key:
     *             type: xml
     *             dir: %kernel.project_dir%/src/vendor/DoctrineExtensions/lib/DoctrineExtensions/Entities
     *             prefix: DoctrineExtensions\Entities\
     *             alias: DExt
     *
     * In the case of bundles everything is really optional (which leads to autodetection for this bundle) but
     * in the mappings key everything except alias is a required argument.
     */
    private function load_orm_entity_manager_mapping_information(array $entity_manager, Definition $orm_config_def, Container_Builder $container): void
    {
        // reset state of drivers and alias map. They are only used by this methods and children.
        $this->drivers = [];
        $this->alias_map = [];
        $this->load_mapping_information($entity_manager, $container);
        $this->register_mapping_drivers($entity_manager, $container);
        $container->get_definition($this->get_object_manager_element_name($entity_manager['name'] . '_metadata_driver'));
        /** @psalm-suppress NoValue $this->drivers is set by $this->loadMappingInformation() call  */
        foreach (array_keys($this->drivers) as $driver_type) {
            $mapping_service = $this->get_object_manager_element_name($entity_manager['name'] . '_' . $driver_type . '_metadata_driver');
            $mapping_driver_def = $container->get_definition($mapping_service);
            $args = $mapping_driver_def->get_arguments();
            if ($driver_type !== 'xml') {
                continue;
            }
            $args[1] ??= Simplified_Xml_Driver::DEFAULT_FILE_EXTENSION;
            $args[2] = $entity_manager['validate_xml_mapping'];
            $mapping_driver_def->set_arguments($args);
        }
        $orm_config_def->add_method_call('setEntityNamespaces', [$this->alias_map]);
    }
    /**
     * Loads an ORM second level cache bundle mapping information.
     *
     * @param array<string, mixed> $entityManager A configured ORM entity manager
     * @param Definition           $ormConfigDef  A Definition instance
     * @param ContainerBuilder     $container     A ContainerBuilder instance
     *
     * @example
     *  entity_managers:
     *      default:
     *          second_level_cache:
     *              region_lifetime: 3600
     *              region_lock_lifetime: 60
     *              region_cache_driver: apc
     *              log_enabled: true
     *              regions:
     *                  my_service_region:
     *                      type: service
     *                      service : "my_service_region"
     *
     *                  my_query_region:
     *                      lifetime: 300
     *                      cache_driver: array
     *                      type: filelock
     *
     *                  my_entity_region:
     *                      lifetime: 600
     *                      cache_driver:
     *                          type: apc
     */
    private function load_orm_second_level_cache(array $entity_manager, Definition $orm_config_def, Container_Builder $container): void
    {
        $driver_id = null;
        $enabled = $entity_manager['second_level_cache']['enabled'];
        if (isset($entity_manager['second_level_cache']['region_cache_driver'])) {
            $driver_name = 'second_level_cache.region_cache_driver';
            $driver_map = $entity_manager['second_level_cache']['region_cache_driver'];
            $driver_id = $this->load_cache_driver($driver_name, $entity_manager['name'], $driver_map, $container);
        }
        $config_id = sprintf('doctrine.orm.%s_second_level_cache.cache_configuration', $entity_manager['name']);
        $regions_id = sprintf('doctrine.orm.%s_second_level_cache.regions_configuration', $entity_manager['name']);
        $driver_id = $driver_id ?: sprintf('doctrine.orm.%s_second_level_cache.region_cache_driver', $entity_manager['name']);
        $config_def = $container->set_definition($config_id, new Definition(Cache_Configuration::class));
        $regions_def = $container->set_definition($regions_id, new Definition(Regions_Configuration::class))->set_arguments([$entity_manager['second_level_cache']['region_lifetime'], $entity_manager['second_level_cache']['region_lock_lifetime']]);
        $slc_factory_id = sprintf('doctrine.orm.%s_second_level_cache.default_cache_factory', $entity_manager['name']);
        $factory_class = $entity_manager['second_level_cache']['factory'] ?? Default_Cache_Factory::class;
        $definition = new Definition($factory_class, [new Reference($regions_id), new Reference($driver_id)]);
        $slc_factory_def = $container->set_definition($slc_factory_id, $definition);
        if (isset($entity_manager['second_level_cache']['regions'])) {
            foreach ($entity_manager['second_level_cache']['regions'] as $name => $region) {
                $region_ref = null;
                $region_type = $region['type'];
                if ($region_type === 'service') {
                    $region_id = sprintf('doctrine.orm.%s_second_level_cache.region.%s', $entity_manager['name'], $name);
                    $region_ref = new Reference($region['service']);
                    $container->set_alias($region_id, new Alias($region['service'], false));
                }
                if ($region_type === 'default' || $region_type === 'filelock') {
                    $region_id = sprintf('doctrine.orm.%s_second_level_cache.region.%s', $entity_manager['name'], $name);
                    $driver_name = sprintf('second_level_cache.region.%s_driver', $name);
                    $driver_map = $region['cache_driver'];
                    $driver_id = $this->load_cache_driver($driver_name, $entity_manager['name'], $driver_map, $container);
                    $region_ref = new Reference($region_id);
                    $container->set_definition($region_id, new Definition(Default_Region::class))->set_arguments([$name, new Reference($driver_id), $region['lifetime']]);
                }
                if ($region_type === 'filelock') {
                    $region_id = sprintf('doctrine.orm.%s_second_level_cache.region.%s_filelock', $entity_manager['name'], $name);
                    $container->set_definition($region_id, new Definition(File_Lock_Region::class))->set_arguments([$region_ref, $region['lock_path'], $region['lock_lifetime']]);
                    $region_ref = new Reference($region_id);
                    $regions_def->add_method_call('getLockLifetime', [$name, $region['lock_lifetime']]);
                }
                $regions_def->add_method_call('setLifetime', [$name, $region['lifetime']]);
                $slc_factory_def->add_method_call('setRegion', [$region_ref]);
            }
        }
        if ($entity_manager['second_level_cache']['log_enabled']) {
            $logger_chain_id = sprintf('doctrine.orm.%s_second_level_cache.logger_chain', $entity_manager['name']);
            $logger_stats_id = sprintf('doctrine.orm.%s_second_level_cache.logger_statistics', $entity_manager['name']);
            $logger_chaing_def = $container->set_definition($logger_chain_id, new Definition(Cache_Logger_Chain::class));
            $logger_stats_def = $container->set_definition($logger_stats_id, new Definition(Statistics_Cache_Logger::class));
            $logger_chaing_def->add_method_call('setLogger', ['statistics', $logger_stats_def]);
            $config_def->add_method_call('setCacheLogger', [$logger_chaing_def]);
            foreach ($entity_manager['second_level_cache']['loggers'] as $name => $logger) {
                $logger_id = sprintf('doctrine.orm.%s_second_level_cache.logger.%s', $entity_manager['name'], $name);
                $logger_ref = new Reference($logger['service']);
                $container->set_alias($logger_id, new Alias($logger['service'], false));
                $logger_chaing_def->add_method_call('setLogger', [$name, $logger_ref]);
            }
        }
        $config_def->add_method_call('setCacheFactory', [$slc_factory_def]);
        $config_def->add_method_call('setRegionsConfiguration', [$regions_def]);
        $orm_config_def->add_method_call('setSecondLevelCacheEnabled', [$enabled]);
        $orm_config_def->add_method_call('setSecondLevelCacheConfiguration', [$config_def]);
    }
    /**
     * Prefixes the relative dependency injection container path with the object manager prefix.
     *
     * @example $name is 'entity_manager' then the result would be 'doctrine.orm.entity_manager'
     */
    private function get_object_manager_element_name(string $name): string
    {
        return 'doctrine.orm.' . $name;
    }
    /**
     * Noun that describes the mapped objects such as Entity or Document.
     *
     * Will be used for autodetection of persistent objects directory.
     */
    private function get_mapping_object_default_name(): string
    {
        return 'Entity';
    }
    /**
     * Relative path from the bundle root to the directory where mapping files reside.
     */
    private function get_mapping_resource_config_directory(string|null $bundle_dir = null): string
    {
        if ($bundle_dir !== null && is_dir($bundle_dir . '/config/doctrine')) {
            return 'config/doctrine';
        }
        return 'Resources/config/doctrine';
    }
    /**
     * Extension used by the mapping files.
     */
    private function get_mapping_resource_extension(): string
    {
        return 'orm';
    }
    /**
     * Loads a cache driver.
     *
     * @param array<string, mixed> $cacheDriver
     *
     * @throws InvalidArgumentException
     */
    private function load_cache_driver(string $cache_name, string $object_manager_name, array $cache_driver, Container_Builder $container): string
    {
        $alias_id = $this->get_object_manager_element_name(sprintf('%s_%s', $object_manager_name, $cache_name));
        $service_id = match ($cache_driver['type'] ?? 'pool') {
            'service' => $cache_driver['id'],
            'pool' => $cache_driver['pool'] ?? $this->create_array_adapter_cache_pool($container, $object_manager_name, $cache_name),
            default => throw new InvalidArgumentException(sprintf('Unknown cache of type "%s" configured for cache "%s" in entity manager "%s".', $cache_driver['type'], $cache_name, $object_manager_name)),
        };
        $container->set_alias($alias_id, new Alias($service_id, false));
        return $alias_id;
    }
    /**
     * Loads a configured entity managers cache drivers.
     *
     * @param array<string, mixed> $entityManager A configured ORM entity manager.
     */
    private function load_orm_cache_drivers(array $entity_manager, Container_Builder $container): void
    {
        if (isset($entity_manager['metadata_cache_driver'])) {
            $this->load_cache_driver('metadata_cache', $entity_manager['name'], $entity_manager['metadata_cache_driver'], $container);
        } else {
            $this->create_metadata_cache($entity_manager['name'], $container);
        }
        $this->load_cache_driver('result_cache', $entity_manager['name'], $entity_manager['result_cache_driver'], $container);
        $this->load_cache_driver('query_cache', $entity_manager['name'], $entity_manager['query_cache_driver'], $container);
    }
    private function create_metadata_cache(string $object_manager_name, Container_Builder $container): void
    {
        $alias_id = $this->get_object_manager_element_name(sprintf('%s_%s', $object_manager_name, 'metadata_cache'));
        $cache_id = sprintf('cache.doctrine.orm.%s.%s', $object_manager_name, 'metadata');
        $cache = new Definition(Array_Adapter::class);
        if (!$container->get_parameter('kernel.debug')) {
            $php_array_file = '%kernel.build_dir%' . sprintf('/doctrine/orm/%s_metadata.php', $object_manager_name);
            $cache_warmer_service_id = $this->get_object_manager_element_name(sprintf('%s_%s', $object_manager_name, 'metadata_cache_warmer'));
            $container->register($cache_warmer_service_id, Doctrine_Metadata_Cache_Warmer::class)->set_arguments([new Reference(sprintf('doctrine.orm.%s_entity_manager', $object_manager_name)), $php_array_file])->add_tag('kernel.cache_warmer', ['priority' => 1000]);
            $cache = new Definition(Php_Array_Adapter::class, [$php_array_file, $cache]);
        }
        $container->set_definition($cache_id, $cache);
        $container->set_alias($alias_id, $cache_id);
    }
    /**
     * Loads a property info extractor for each defined entity manager.
     */
    private function load_property_info_extractor(string $entity_manager_name, Container_Builder $container): void
    {
        $property_extractor_definition = $container->register(sprintf('doctrine.orm.%s_entity_manager.property_info_extractor', $entity_manager_name), Doctrine_Extractor::class);
        $argument_id = sprintf('doctrine.orm.%s_entity_manager', $entity_manager_name);
        $property_extractor_definition->add_argument(new Reference($argument_id));
        $property_extractor_definition->add_tag('property_info.list_extractor', ['priority' => -1001]);
        $property_extractor_definition->add_tag('property_info.type_extractor', ['priority' => -999]);
        $property_extractor_definition->add_tag('property_info.access_extractor', ['priority' => -999]);
    }
    /**
     * Loads a validator loader for each defined entity manager.
     */
    private function load_validator_loader(string $entity_manager_name, Container_Builder $container): void
    {
        $validator_loader_definition = $container->register(sprintf('doctrine.orm.%s_entity_manager.validator_loader', $entity_manager_name), Doctrine_Loader::class);
        $validator_loader_definition->add_argument(new Reference(sprintf('doctrine.orm.%s_entity_manager', $entity_manager_name)));
        $validator_loader_definition->add_tag('validator.auto_mapper', ['priority' => -100]);
    }
    public function get_xsd_validation_base_path(): string
    {
        return __DIR__ . '/../../config/schema';
    }
    public function get_namespace(): string
    {
        return 'http://symfony.com/schema/dic/doctrine';
    }
    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $config
     */
    public function get_configuration(array $config, Container_Builder $container): Configuration
    {
        return new Configuration((bool) $container->get_parameter('kernel.debug'));
    }
    /**
     * The class name used by the various mapping drivers.
     */
    private function get_metadata_driver_class(string $driver_type): string
    {
        return match ($driver_type) {
            'driver_chain' => Mapping_Driver_Chain::class,
            'xml' => Simplified_Xml_Driver::class,
            'php' => Php_Driver::class,
            'staticphp' => Static_Php_Driver::class,
            'attribute' => Attribute_Driver::class,
            default => throw new LogicException(sprintf('Unknown "%s" metadata driver type.', $driver_type)),
        };
    }
    private function load_messenger_services(Container_Builder $container): void
    {
        // If the Messenger component is installed, wire it:
        if (!interface_exists(Message_Bus_Interface::class)) {
            return;
        }
        $loader = new Php_File_Loader($container, new File_Locator(__DIR__ . '/../../config'));
        $loader->load('messenger.php');
        /**
         * The Doctrine transport component (symfony/doctrine-messenger) is optional.
         * Remove service definition, if it is not available
         */
        if (class_exists(Doctrine_Transport_Factory::class)) {
            return;
        }
        $container->remove_definition('messenger.transport.doctrine.factory');
        $container->remove_definition('doctrine.orm.messenger.doctrine_schema_listener');
    }
    private function create_array_adapter_cache_pool(Container_Builder $container, string $object_manager_name, string $cache_name): string
    {
        $id = sprintf('cache.doctrine.orm.%s.%s', $object_manager_name, str_replace('_cache', '', $cache_name));
        $pool_definition = $container->register($id, Array_Adapter::class);
        $pool_definition->add_tag('cache.pool');
        $container->set_definition($id, $pool_definition);
        return $id;
    }
    /**
     * @param string[] $connWithLogging
     * @param string[] $connWithProfiling
     * @param string[] $connWithBacktrace
     * @param string[] $connWithTtl
     */
    private function register_dbal_middlewares(Container_Builder $container, array $conn_with_logging, array $conn_with_profiling, array $conn_with_backtrace, array $conn_with_ttl): void
    {
        $loader = new Php_File_Loader($container, new File_Locator(__DIR__ . '/../../config'));
        $loader->load('middlewares.php');
        $logging_middleware_abstract_def = $container->get_definition('doctrine.dbal.logging_middleware');
        foreach ($conn_with_logging as $conn_name) {
            $logging_middleware_abstract_def->add_tag('doctrine.middleware', ['connection' => $conn_name, 'priority' => 10]);
        }
        $container->get_definition('doctrine.debug_data_holder')->replace_argument(0, $conn_with_backtrace);
        $debug_middleware_abstract_def = $container->get_definition('doctrine.dbal.debug_middleware');
        foreach ($conn_with_profiling as $conn_name) {
            $debug_middleware_abstract_def->add_tag('doctrine.middleware', ['connection' => $conn_name, 'priority' => 10]);
        }
        $idle_connection_middleware_abstract_def = $container->get_definition('doctrine.dbal.idle_connection_middleware');
        foreach ($conn_with_ttl as $conn_name) {
            $idle_connection_middleware_abstract_def->add_tag('doctrine.middleware', ['connection' => $conn_name, 'priority' => 10]);
        }
    }
}