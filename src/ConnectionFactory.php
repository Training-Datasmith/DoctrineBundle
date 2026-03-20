<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle;

use function array_merge;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connection\Static_Server_Version_Provider;
use Doctrine\DBAL\Connection_Exception;
use Doctrine\DBAL\Driver_Manager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\Driver_Exception;
use Doctrine\DBAL\Exception\Driver_Required;
use Doctrine\DBAL\Exception\Invalid_Wrapper_Class;
use Doctrine\DBAL\Exception\Malformed_Dsn_Exception;
use Doctrine\DBAL\Platforms\Abstract_My_Sql_Platform;
use Doctrine\DBAL\Platforms\Abstract_Platform;
use Doctrine\DBAL\Tools\Dsn_Parser;
use Doctrine\DBAL\Types\Type;
use function is_subclass_of;
use const PHP_EOL;
/**
 * @internal This class is not meant to be used outside this bundle
 *
 * @phpstan-type Params = array<string, mixed>
 */
final class Connection_Factory
{
    /** @internal */
    public const array DEFAULT_SCHEME_MAP = [
        'db2' => 'ibm_db2',
        'mssql' => 'pdo_sqlsrv',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        // Amazon RDS, for some weird reason
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];
    private readonly Dsn_Parser $dsn_parser;
    private bool $initialized = false;
    /** @param mixed[][] $typesConfig */
    public function __construct(private readonly array $types_config = [], Dsn_Parser|null $dsn_parser = null)
    {
        $this->dsn_parser = $dsn_parser ?? new Dsn_Parser(self::DEFAULT_SCHEME_MAP);
    }
    /**
     * Create a connection by name.
     *
     * @param array<string, string> $mappingTypes
     * @phpstan-param Params $params
     */
    public function create_connection(array $params, Configuration|null $config = null, array $mapping_types = []): Connection
    {
        if (!$this->initialized) {
            $this->initialize_types();
        }
        $params = $this->parse_database_url($params);
        // URL support for PrimaryReplicaConnection
        if (isset($params['primary'])) {
            $params['primary'] = $this->parse_database_url($params['primary']);
        }
        if (isset($params['replica'])) {
            foreach ($params['replica'] as $key => $replica_params) {
                $params['replica'][$key] = $this->parse_database_url($replica_params);
            }
        }
        if (!isset($params['pdo']) && (!isset($params['charset']) || isset($params['dbname_suffix']))) {
            $wrapper_class = null;
            if (isset($params['wrapperClass'])) {
                if (!is_subclass_of($params['wrapperClass'], Connection::class)) {
                    throw Invalid_Wrapper_Class::new($params['wrapperClass']);
                }
                $wrapper_class = $params['wrapperClass'];
                $params['wrapperClass'] = null;
            }
            $connection = Driver_Manager::get_connection($params, $config);
            $params = $this->add_database_suffix($connection->get_params());
            $driver = $connection->get_driver();
            $platform = $driver->get_database_platform(new Static_Server_Version_Provider($params['serverVersion'] ?? $params['primary']['serverVersion'] ?? ''));
            if (!isset($params['charset'])) {
                if ($platform instanceof Abstract_My_Sql_Platform) {
                    $params['charset'] = 'utf8mb4';
                    if (!isset($params['defaultTableOptions']['collation'])) {
                        $params['defaultTableOptions']['collation'] = 'utf8mb4_unicode_ci';
                    }
                } else {
                    $params['charset'] = 'utf8';
                }
            }
            if ($wrapper_class !== null) {
                $params['wrapperClass'] = $wrapper_class;
            } else {
                $wrapper_class = Connection::class;
            }
            $connection = new $wrapper_class($params, $driver, $config);
        } else {
            $connection = Driver_Manager::get_connection($params, $config);
        }
        if (!empty($mapping_types)) {
            $platform = $this->get_database_platform($connection);
            foreach ($mapping_types as $db_type => $doctrine_type) {
                $platform->register_doctrine_type_mapping($db_type, $doctrine_type);
            }
        }
        return $connection;
    }
    /**
     * Try to get the database platform.
     *
     * This could fail if types should be registered to an predefined/unused connection
     * and the platform version is unknown.
     *
     * @link https://github.com/doctrine/DoctrineBundle/issues/673
     *
     * @throws DBALException
     */
    private function get_database_platform(Connection $connection): Abstract_Platform
    {
        try {
            return $connection->get_database_platform();
        } catch (Driver_Exception $driver_exception) {
            throw new Connection_Exception('An exception occurred while establishing a connection to figure out your platform version.' . PHP_EOL . "You can circumvent this by setting a 'server_version' configuration value" . PHP_EOL . PHP_EOL . 'For further information have a look at:' . PHP_EOL . 'https://github.com/doctrine/DoctrineBundle/issues/673', 0, $driver_exception);
        }
    }
    /**
     * initialize the types
     */
    private function initialize_types(): void
    {
        foreach ($this->types_config as $type_name => $type_config) {
            if (Type::has_type($type_name)) {
                Type::override_type($type_name, $type_config['class']);
            } else {
                Type::add_type($type_name, $type_config['class']);
            }
        }
        $this->initialized = true;
    }
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function add_database_suffix(array $params): array
    {
        if (isset($params['dbname']) && isset($params['dbname_suffix'])) {
            $params['dbname'] .= $params['dbname_suffix'];
        }
        foreach ($params['replica'] ?? [] as $key => $replica_params) {
            if (!isset($replica_params['dbname'], $replica_params['dbname_suffix'])) {
                continue;
            }
            $params['replica'][$key]['dbname'] .= $replica_params['dbname_suffix'];
        }
        if (isset($params['primary']['dbname'], $params['primary']['dbname_suffix'])) {
            $params['primary']['dbname'] .= $params['primary']['dbname_suffix'];
        }
        return $params;
    }
    /**
     * Extracts parts from a database URL, if present, and returns an
     * updated list of parameters.
     *
     * @param mixed[] $params The list of parameters.
     * @phpstan-param Params $params
     *
     * @return Params params A modified list of parameters with info from a database
     *                 URL extracted into individual parameter parts.
     * @phpstan-return Params
     *
     * @throws DBALException
     */
    private function parse_database_url(array $params): array
    {
        if (!isset($params['url'])) {
            return $params;
        }
        try {
            $parsed_params = $this->dsn_parser->parse($params['url']);
        } catch (Malformed_Dsn_Exception $e) {
            throw new Malformed_Dsn_Exception('Malformed parameter "url".', 0, $e);
        }
        if (isset($parsed_params['driver'])) {
            // The requested driver from the URL scheme takes precedence
            // over the default custom driver from the connection parameters (if any).
            unset($params['driverClass']);
        }
        $params = array_merge($params, $parsed_params);
        // If a schemeless connection URL is given, we require a default driver or default custom driver
        // as connection parameter.
        if (!isset($params['driverClass']) && !isset($params['driver'])) {
            throw Driver_Required::new($params['url']);
        }
        unset($params['url']);
        return $params;
    }
}