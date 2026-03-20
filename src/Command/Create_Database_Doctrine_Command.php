<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Command;

use Doctrine\DBAL\Driver_Manager;
use Doctrine\DBAL\Platforms\Postgre_Sql_Platform;
use function in_array;
use InvalidArgumentException;
use function sprintf;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Throwable;
/**
 * Database tool allows you to easily create your configured databases.
 *
 * @final
 */
class Create_Database_Doctrine_Command extends Doctrine_Command
{
    protected function configure(): void
    {
        $this->set_name('doctrine:database:create')->set_description('Creates the configured database')->add_option('connection', 'c', Input_Option::VALUE_REQUIRED, 'The connection to use for this command')->add_option('if-not-exists', null, Input_Option::VALUE_NONE, 'Don\'t trigger an error, when the database already exists')->set_help(<<<'EOT'
        The <info>%command.name%</info> command creates the default connections database:
        
            <info>php %command.full_name%</info>
        
        You can also optionally specify the name of a connection to create the database for:
        
            <info>php %command.full_name% --connection=default</info>
        EOT);
    }
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $connection_name = $input->get_option('connection');
        if (empty($connection_name)) {
            $connection_name = $this->get_doctrine()->get_default_connection_name();
        }
        $connection = $this->get_doctrine_connection($connection_name);
        $if_not_exists = $input->get_option('if-not-exists');
        $params = $connection->get_params();
        if (isset($params['primary'])) {
            $params = $params['primary'];
        }
        $has_path = isset($params['path']);
        $name = $has_path ? $params['path'] : $params['dbname'] ?? false;
        if (!$name) {
            throw new InvalidArgumentException("Connection does not contain a 'path' or 'dbname' parameter and cannot be created.");
        }
        // Need to get rid of _every_ occurrence of dbname from connection configuration as we have already extracted all relevant info from url
        /** @psalm-suppress InvalidArrayOffset Need to be compatible with DBAL < 4, which still has `$params['url']` */
        /** @phpstan-ignore unset.offset */
        unset($params['dbname'], $params['path'], $params['url']);
        if ($connection->get_database_platform() instanceof Postgre_Sql_Platform) {
            /** @phpstan-ignore nullCoalesce.offset (needed for DBAL < 4) */
            $params['dbname'] = $params['default_dbname'] ?? 'postgres';
        }
        $tmp_connection = Driver_Manager::get_connection($params, $connection->get_configuration());
        $schema_manager = $tmp_connection->create_schema_manager();
        $should_not_create_database = $if_not_exists && in_array($name, $schema_manager->list_databases());
        // Only quote if we don't have a path
        if (!$has_path) {
            $name = $tmp_connection->get_database_platform()->quote_single_identifier($name);
        }
        $error = false;
        try {
            if ($should_not_create_database) {
                $output->writeln(sprintf('<info>Database <comment>%s</comment> for connection named <comment>%s</comment> already exists. Skipped.</info>', $name, $connection_name));
            } else {
                $schema_manager->create_database($name);
                $output->writeln(sprintf('<info>Created database <comment>%s</comment> for connection named <comment>%s</comment></info>', $name, $connection_name));
            }
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Could not create database <comment>%s</comment> for connection named <comment>%s</comment></error>', $name, $connection_name));
            $output->writeln(sprintf('<error>%s</error>', $e->get_message()));
            $error = true;
        }
        $tmp_connection->close();
        return $error ? 1 : 0;
    }
}