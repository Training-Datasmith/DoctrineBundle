<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection;

use function array_diff_key;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function class_exists;
use function constant;
use function count;
use Doctrine\ORM\Entity_Manager;
use Doctrine\ORM\Entity_Repository;
use Doctrine\ORM\Mapping\Class_Metadata;
use Doctrine\ORM\Mapping\Class_Metadata_Factory;
use function implode;
use function in_array;
use InvalidArgumentException;
use function is_array;
use function is_string;
use function key;
use function reset;
use RuntimeException;
use function sprintf;
use function strtoupper;
use Symfony\Component\Config\Definition\Builder\Array_Node_Definition;
use Symfony\Component\Config\Definition\Builder\Node_Definition;
use Symfony\Component\Config\Definition\Builder\Tree_Builder;
use Symfony\Component\Config\Definition\Configuration_Interface;
use Symfony\Component\Dependency_Injection\Exception\LogicException;
/**
 * This class contains the configuration information for the bundle
 *
 * This information is solely responsible for how the different configuration
 * sections are normalized, and merged.
 *
 * @internal
 */
final readonly class Configuration implements Configuration_Interface
{
    /** @param bool $debug Whether to use the debug mode */
    public function __construct(private bool $debug)
    {
    }
    public function get_config_tree_builder(): Tree_Builder
    {
        $tree_builder = new Tree_Builder('doctrine');
        $root_node = $tree_builder->get_root_node();
        $this->add_dbal_section($root_node);
        $this->add_orm_section($root_node);
        return $tree_builder;
    }
    /**
     * Add DBAL section to configuration tree
     */
    private function add_dbal_section(Array_Node_Definition $node): void
    {
        // Key that should not be rewritten to the connection config
        $excluded_keys = ['default_connection' => true, 'driver_schemes' => true, 'driver_scheme' => true, 'types' => true, 'type' => true];
        $node->children()->array_node('dbal')->before_normalization()->if_true(static function ($v) use ($excluded_keys): bool {
            if (!is_array($v)) {
                return false;
            }
            if (array_key_exists('connections', $v) || array_key_exists('connection', $v)) {
                return false;
            }
            // Is there actually anything to use once excluded keys are considered?
            return (bool) array_diff_key($v, $excluded_keys);
        })->then(static function (array $v) use ($excluded_keys): array {
            $connection = [];
            foreach ($v as $key => $value) {
                if (isset($excluded_keys[$key])) {
                    continue;
                }
                $connection[$key] = $v[$key];
                unset($v[$key]);
            }
            $v['connections'] = [$v['default_connection'] ?? 'default' => $connection];
            return $v;
        })->end()->children()->scalar_node('default_connection')->end()->end()->fix_xml_config('type')->children()->array_node('types')->use_attribute_as_key('name')->prototype('array')->before_normalization()->if_string()->then(static fn($v): array => ['class' => $v])->end()->children()->scalar_node('class')->is_required()->end()->end()->end()->end()->end()->fix_xml_config('driver_scheme')->children()->array_node('driver_schemes')->use_attribute_as_key('scheme')->normalize_keys(false)->scalar_prototype()->end()->info('Defines a driver for given URL schemes. Schemes being driver names cannot be redefined. However, other default schemes can be overwritten.')->validate()->always()->then(static function (array $value): array {
            $unsupported_schemes = [];
            foreach ($value as $scheme => $driver) {
                if (!in_array($scheme, ['pdo-mysql', 'pdo-sqlite', 'pdo-pgsql', 'pdo-oci', 'oci8', 'ibm-db2', 'pdo-sqlsrv', 'mysqli', 'pgsql', 'sqlsrv', 'sqlite3'], true)) {
                    continue;
                }
                $unsupported_schemes[] = $scheme;
            }
            if ($unsupported_schemes) {
                throw new InvalidArgumentException(sprintf('Registering a scheme with the name of one of the official drivers is forbidden, as those are defined in DBAL itself. The following schemes are forbidden: %s', implode(', ', $unsupported_schemes)));
            }
            return $value;
        })->end()->end()->end()->fix_xml_config('connection')->append($this->get_dbal_connections_node())->end();
    }
    /**
     * Return the dbal connections node
     */
    private function get_dbal_connections_node(): Array_Node_Definition
    {
        $tree_builder = new Tree_Builder('connections');
        $node = $tree_builder->get_root_node();
        $connection_node = $node->requires_at_least_one_element()->use_attribute_as_key('name')->prototype('array');
        $this->configure_dbal_driver_node($connection_node);
        $connection_node->fix_xml_config('option')->fix_xml_config('mapping_type')->fix_xml_config('replica')->fix_xml_config('default_table_option')->children()->scalar_node('driver')->default_value('pdo_mysql')->end()->boolean_node('auto_commit')->end()->scalar_node('schema_filter')->end()->boolean_node('logging')->default_value($this->debug)->end()->boolean_node('profiling')->default_value($this->debug)->end()->boolean_node('profiling_collect_backtrace')->default_value(false)->info('Enables collecting backtraces when profiling is enabled')->end()->boolean_node('profiling_collect_schema_errors')->default_value(true)->info('Enables collecting schema errors when profiling is enabled')->end()->scalar_node('server_version')->end()->integer_node('idle_connection_ttl')->default_value(600)->end()->scalar_node('driver_class')->end()->scalar_node('wrapper_class')->end()->boolean_node('keep_replica')->end()->array_node('options')->use_attribute_as_key('key')->prototype('variable')->end()->end()->array_node('mapping_types')->use_attribute_as_key('name')->prototype('scalar')->end()->end()->array_node('default_table_options')->info("This option is used by the schema-tool and affects generated SQL. Possible keys include 'charset','collation', and 'engine'.")->use_attribute_as_key('name')->prototype('scalar')->end()->end()->scalar_node('schema_manager_factory')->cannot_be_empty()->default_value('doctrine.dbal.default_schema_manager_factory')->end()->scalar_node('result_cache')->end()->end();
        $replica_node = $connection_node->children()->array_node('replicas')->use_attribute_as_key('name')->prototype('array');
        $this->configure_dbal_driver_node($replica_node);
        return $node;
    }
    /**
     * Adds config keys related to params processed by the DBAL drivers
     *
     * These keys are available for replica configurations too.
     */
    private function configure_dbal_driver_node(Array_Node_Definition $node): void
    {
        $node->validate()->always(static function (array $values) {
            if (!isset($values['url'])) {
                return $values;
            }
            $url_conflicting_options = ['host' => true, 'port' => true, 'user' => true, 'password' => true, 'path' => true, 'dbname' => true, 'unix_socket' => true, 'memory' => true];
            $url_conflicting_values = array_keys(array_intersect_key($values, $url_conflicting_options));
            if ($url_conflicting_values) {
                $tail = count($url_conflicting_values) > 1 ? sprintf('or "%s" options', array_pop($url_conflicting_values)) : 'option';
                throw new RuntimeException(sprintf('Setting the "doctrine.dbal.%s" %s while the "url" one is defined is not allowed.', implode('", "', $url_conflicting_values), $tail));
            }
            return $values;
        })->end()->children()->scalar_node('url')->info('A URL with connection information; any parameter value parsed from this string will override explicitly set parameters')->end()->scalar_node('dbname')->end()->scalar_node('host')->info('Defaults to "localhost" at runtime.')->end()->scalar_node('port')->info('Defaults to null at runtime.')->end()->scalar_node('user')->info('Defaults to "root" at runtime.')->end()->scalar_node('password')->info('Defaults to null at runtime.')->end()->scalar_node('dbname_suffix')->info('Adds the given suffix to the configured database name, this option has no effects for the SQLite platform')->end()->scalar_node('application_name')->end()->scalar_node('charset')->end()->scalar_node('path')->end()->boolean_node('memory')->end()->scalar_node('unix_socket')->info('The unix socket to use for MySQL')->end()->boolean_node('persistent')->info('True to use as persistent connection for the ibm_db2 driver')->end()->scalar_node('protocol')->info('The protocol to use for the ibm_db2 driver (default to TCPIP if omitted)')->end()->boolean_node('service')->info('True to use SERVICE_NAME as connection parameter instead of SID for Oracle')->end()->scalar_node('servicename')->info('Overrules dbname parameter if given and used as SERVICE_NAME or SID connection parameter ' . 'for Oracle depending on the service parameter.')->end()->scalar_node('sessionMode')->info('The session mode to use for the oci8 driver')->end()->scalar_node('server')->info('The name of a running database server to connect to for SQL Anywhere.')->end()->scalar_node('default_dbname')->info('Override the default database (postgres) to connect to for PostgreSQL connection.')->end()->scalar_node('sslmode')->info('Determines whether or with what priority a SSL TCP/IP connection will be negotiated with ' . 'the server for PostgreSQL.')->end()->scalar_node('sslrootcert')->info('The name of a file containing SSL certificate authority (CA) certificate(s). ' . 'If the file exists, the server\'s certificate will be verified to be signed by one of these authorities.')->end()->scalar_node('sslcert')->info('The path to the SSL client certificate file for PostgreSQL.')->end()->scalar_node('sslkey')->info('The path to the SSL client key file for PostgreSQL.')->end()->scalar_node('sslcrl')->info('The file name of the SSL certificate revocation list for PostgreSQL.')->end()->boolean_node('pooled')->info('True to use a pooled server with the oci8/pdo_oracle driver')->end()->boolean_node('MultipleActiveResultSets')->info('Configuring MultipleActiveResultSets for the pdo_sqlsrv driver')->end()->scalar_node('instancename')->info('Optional parameter, complete whether to add the INSTANCE_NAME parameter in the connection.' . ' It is generally used to connect to an Oracle RAC server to select the name' . ' of a particular instance.')->end()->scalar_node('connectstring')->info('Complete Easy Connect connection descriptor, see https://docs.oracle.com/database/121/NETAG/naming.htm.' . 'When using this option, you will still need to provide the user and password parameters, but the other ' . 'parameters will no longer be used. Note that when using this parameter, the getHost and getPort methods' . ' from Doctrine\DBAL\Connection will no longer function as expected.')->end()->end()->before_normalization()->if_true(static fn($v): bool => !isset($v['sessionMode']) && isset($v['session_mode']))->then(static function (array $v) {
            $v['sessionMode'] = $v['session_mode'];
            unset($v['session_mode']);
            return $v;
        })->end()->before_normalization()->if_true(static fn($v): bool => !isset($v['MultipleActiveResultSets']) && isset($v['multiple_active_result_sets']))->then(static function (array $v) {
            $v['MultipleActiveResultSets'] = $v['multiple_active_result_sets'];
            unset($v['multiple_active_result_sets']);
            return $v;
        })->end();
    }
    /**
     * Add the ORM section to configuration tree
     */
    private function add_orm_section(Array_Node_Definition $node): void
    {
        // Key that should not be rewritten to the entity-manager config
        $excluded_keys = ['default_entity_manager' => true, 'enable_native_lazy_objects' => true, 'resolve_target_entities' => true, 'resolve_target_entity' => true, 'controller_resolver' => true];
        $node->children()->array_node('orm')->before_normalization()->if_true(static function ($v) use ($excluded_keys): bool {
            if (!empty($v) && !class_exists(Entity_Manager::class)) {
                throw new LogicException('The doctrine/orm package is required when the doctrine.orm config is set.');
            }
            if (!is_array($v)) {
                return false;
            }
            if (array_key_exists('entity_managers', $v) || array_key_exists('entity_manager', $v)) {
                return false;
            }
            // Is there actually anything to use once excluded keys are considered?
            return (bool) array_diff_key($v, $excluded_keys);
        })->then(static function (array $v) use ($excluded_keys): array {
            $entity_manager = [];
            foreach ($v as $key => $value) {
                if (isset($excluded_keys[$key])) {
                    continue;
                }
                $entity_manager[$key] = $v[$key];
                unset($v[$key]);
            }
            $v['entity_managers'] = [$v['default_entity_manager'] ?? 'default' => $entity_manager];
            return $v;
        })->end()->children()->scalar_node('default_entity_manager')->end()->boolean_node('enable_native_lazy_objects')->default_true()->validate()->if_true(static fn($v): bool => $v === false)->then_invalid('The setting "enable_native_lazy_objects" can no longer be disabled and should not be set')->end()->set_deprecated('doctrine/doctrine-bundle', '3.1', 'The "%node%" option is deprecated and will be removed in DoctrineBundle 4.0, as native lazy objects are now always enabled.')->end()->array_node('controller_resolver')->can_be_disabled()->children()->boolean_node('auto_mapping')->default_false()->validate()->if_true(static fn($v): bool => $v !== false)->then_invalid('The setting "controller_resolver.auto_mapping" can no longer be enabled and must be set to false')->end()->set_deprecated('doctrine/doctrine-bundle', '3.1', 'The "%path%.%node%" option is deprecated and will be removed in DoctrineBundle 4.0, as it only accepts `false` since 3.0.')->info('Set to true to enable using route placeholders as lookup criteria when the primary key doesn\'t match the argument name')->end()->boolean_node('evict_cache')->info('Set to true to fetch the entity from the database instead of using the cache, if any')->default_false()->end()->end()->end()->end()->fix_xml_config('entity_manager')->append($this->get_orm_entity_managers_node())->fix_xml_config('resolve_target_entity', 'resolve_target_entities')->append($this->get_orm_target_entity_resolver_node())->end()->end();
    }
    /**
     * Return ORM target entity resolver node
     *
     * @return ArrayNodeDefinition<TreeBuilder<'array'>>
     */
    private function get_orm_target_entity_resolver_node(): Node_Definition
    {
        $tree_builder = new Tree_Builder('resolve_target_entities');
        $node = $tree_builder->get_root_node();
        $node->use_attribute_as_key('interface')->prototype('scalar')->cannot_be_empty()->end();
        return $node;
    }
    /**
     * Return ORM entity listener node
     *
     * @return ArrayNodeDefinition<TreeBuilder<'array'>>
     */
    private function get_orm_entity_listeners_node(): Node_Definition
    {
        $tree_builder = new Tree_Builder('entity_listeners');
        $node = $tree_builder->get_root_node();
        $normalizer = static function ($mappings): array {
            $entities = [];
            foreach ($mappings as $entity_class => $mapping) {
                $listeners = [];
                foreach ($mapping as $listener_class => $listener_event) {
                    $events = [];
                    foreach ($listener_event as $event_type => $event_mapping) {
                        if ($event_mapping === null) {
                            $event_mapping = [null];
                        }
                        foreach ($event_mapping as $method) {
                            $events[] = ['type' => $event_type, 'method' => $method];
                        }
                    }
                    $listeners[] = ['class' => $listener_class, 'event' => $events];
                }
                $entities[] = ['class' => $entity_class, 'listener' => $listeners];
            }
            return ['entities' => $entities];
        };
        $node->before_normalization()->if_true(static fn($v): bool => is_array(reset($v)) && is_string(key(reset($v))))->then($normalizer)->end()->fix_xml_config('entity', 'entities')->children()->array_node('entities')->use_attribute_as_key('class')->prototype('array')->fix_xml_config('listener')->children()->array_node('listeners')->use_attribute_as_key('class')->prototype('array')->fix_xml_config('event')->children()->array_node('events')->prototype('array')->children()->scalar_node('type')->end()->scalar_node('method')->default_null()->end()->end()->end()->end()->end()->end()->end()->end()->end()->end()->end();
        return $node;
    }
    /**
     * Return ORM entity manager node
     */
    private function get_orm_entity_managers_node(): Array_Node_Definition
    {
        $tree_builder = new Tree_Builder('entity_managers');
        $node = $tree_builder->get_root_node();
        $node->requires_at_least_one_element()->use_attribute_as_key('name')->prototype('array')->add_defaults_if_not_set()->append($this->get_orm_cache_driver_node('query_cache_driver'))->append($this->get_orm_cache_driver_node('metadata_cache_driver'))->append($this->get_orm_cache_driver_node('result_cache_driver'))->append($this->get_orm_entity_listeners_node())->fix_xml_config('schema_ignore_class', 'schema_ignore_classes')->children()->scalar_node('connection')->end()->scalar_node('class_metadata_factory_name')->default_value(Class_Metadata_Factory::class)->end()->scalar_node('default_repository_class')->default_value(Entity_Repository::class)->end()->scalar_node('auto_mapping')->default_false()->end()->scalar_node('naming_strategy')->default_value('doctrine.orm.naming_strategy.default')->end()->scalar_node('quote_strategy')->default_value('doctrine.orm.quote_strategy.default')->end()->scalar_node('typed_field_mapper')->default_value('doctrine.orm.typed_field_mapper.default')->end()->scalar_node('entity_listener_resolver')->default_null()->end()->scalar_node('fetch_mode_subselect_batch_size')->end()->scalar_node('repository_factory')->default_value('doctrine.orm.container_repository_factory')->end()->array_node('schema_ignore_classes')->prototype('scalar')->end()->end()->boolean_node('validate_xml_mapping')->default_false()->info('Set to "true" to opt-in to the new mapping driver mode that was added in Doctrine ORM 2.14 and will be mandatory in ORM 3.0. See https://github.com/doctrine/orm/pull/6728.')->end()->end()->children()->array_node('second_level_cache')->children()->append($this->get_orm_cache_driver_node('region_cache_driver'))->scalar_node('region_lock_lifetime')->default_value(60)->end()->boolean_node('log_enabled')->default_value($this->debug)->end()->scalar_node('region_lifetime')->default_value(3600)->end()->boolean_node('enabled')->default_value(true)->end()->scalar_node('factory')->end()->end()->fix_xml_config('region')->children()->array_node('regions')->use_attribute_as_key('name')->prototype('array')->children()->append($this->get_orm_cache_driver_node('cache_driver'))->scalar_node('lock_path')->default_value('%kernel.cache_dir%/doctrine/orm/slc/filelock')->end()->scalar_node('lock_lifetime')->default_value(60)->end()->scalar_node('type')->default_value('default')->end()->scalar_node('lifetime')->default_value(0)->end()->scalar_node('service')->end()->scalar_node('name')->end()->end()->end()->end()->end()->fix_xml_config('logger')->children()->array_node('loggers')->use_attribute_as_key('name')->prototype('array')->children()->scalar_node('name')->end()->scalar_node('service')->end()->end()->end()->end()->end()->end()->end()->fix_xml_config('hydrator')->children()->array_node('hydrators')->use_attribute_as_key('name')->prototype('scalar')->end()->end()->end()->fix_xml_config('mapping')->children()->array_node('mappings')->use_attribute_as_key('name')->prototype('array')->before_normalization()->if_string()->then(static fn($v): array => ['type' => $v])->end()->treat_null_like([])->treat_false_like(['mapping' => false])->perform_no_deep_merging()->children()->scalar_node('mapping')->default_value(true)->end()->scalar_node('type')->end()->scalar_node('dir')->end()->scalar_node('alias')->end()->scalar_node('prefix')->end()->boolean_node('is_bundle')->end()->end()->end()->end()->array_node('dql')->fix_xml_config('string_function')->fix_xml_config('numeric_function')->fix_xml_config('datetime_function')->children()->array_node('string_functions')->use_attribute_as_key('name')->prototype('scalar')->end()->end()->array_node('numeric_functions')->use_attribute_as_key('name')->prototype('scalar')->end()->end()->array_node('datetime_functions')->use_attribute_as_key('name')->prototype('scalar')->end()->end()->end()->end()->end()->fix_xml_config('filter')->children()->array_node('filters')->info('Register SQL Filters in the entity manager')->use_attribute_as_key('name')->prototype('array')->before_normalization()->if_string()->then(static fn($v): array => ['class' => $v])->end()->before_normalization()->if_true(static fn($v): bool => is_array($v) && isset($v['value']))->then(static function (array $v) {
            $v['class'] = $v['value'];
            unset($v['value']);
            return $v;
        })->end()->fix_xml_config('parameter')->children()->scalar_node('class')->is_required()->end()->boolean_node('enabled')->default_false()->end()->array_node('parameters')->use_attribute_as_key('name')->prototype('variable')->end()->end()->end()->end()->end()->end()->fix_xml_config('identity_generation_preference')->children()->array_node('identity_generation_preferences')->info('Configures the preferences for identity generation when using the AUTO strategy. Valid values are "SEQUENCE" or "IDENTITY".')->use_attribute_as_key('platform')->prototype('scalar')->before_normalization()->if_string()->then(static fn(string $v): mixed => constant(Class_Metadata::class . '::GENERATOR_TYPE_' . strtoupper($v)))->end()->end()->end()->end()->end();
        return $node;
    }
    /**
     * Return an ORM cache driver node for a given entity manager
     */
    private function get_orm_cache_driver_node(string $name): Array_Node_Definition
    {
        $tree_builder = new Tree_Builder($name);
        $node = $tree_builder->get_root_node();
        $node->before_normalization()->if_string()->then(static fn($v): array => ['type' => $v])->end()->children()->scalar_node('type')->default_null()->end()->scalar_node('id')->end()->scalar_node('pool')->end()->end();
        if ($name !== 'metadata_cache_driver') {
            $node->add_defaults_if_not_set();
        }
        return $node;
    }
}