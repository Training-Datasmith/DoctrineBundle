<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Command;

use Doctrine\DBAL\Driver_Manager;
use Doctrine\DBAL\Platforms\Postgre_Sql_Platform;
use Doctrine\DBAL\Schema\Sq_Lite_Schema_Manager;
use function file_exists;
use function in_array;
use InvalidArgumentException;
use function sprintf;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Throwable;
use function unlink;
/**
 * Database tool allows you to easily drop your configured databases.
 *
 * @final
 */
class Drop_Database_Doctrine_Command extends Doctrine_Command
{
    public const int RETURN_CODE_NOT_DROP = 1;
    public const int RETURN_CODE_NO_FORCE = 2;
    protected function configure(): void
    {
        $this->set_name('doctrine:database:drop')->set_description('Drops the configured database')->add_option('connection', 'c', Input_Option::VALUE_REQUIRED, 'The connection to use for this command')->add_option('if-exists', null, Input_Option::VALUE_NONE, 'Don\'t trigger an error, when the database doesn\'t exist')->add_option('force', 'f', Input_Option::VALUE_NONE, 'Set this parameter to execute this action')->set_help(<<<'EOT'
        The <info>%command.name%</info> command drops the default connections database:
        
            <info>php %command.full_name%</info>
        
        The <info>--force</info> parameter has to be used to actually drop the database.
        
        You can also optionally specify the name of a connection to drop the database for:
        
            <info>php %command.full_name% --connection=default</info>
        
        <error>Be careful: All data in a given database will be lost when executing this command.</error>
        EOT);
    }
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $connection_name = $input->get_option('connection');
        if (empty($connection_name)) {
            $connection_name = $this->get_doctrine()->get_default_connection_name();
        }
        $connection = $this->get_doctrine_connection($connection_name);
        $if_exists = $input->get_option('if-exists');
        $params = $connection->get_params();
        if (isset($params['primary'])) {
            $params = $params['primary'];
        }
        $name = $params['path'] ?? $params['dbname'] ?? false;
        if (!$name) {
            throw new InvalidArgumentException("Connection does not contain a 'path' or 'dbname' parameter and cannot be dropped.");
        }
        /* @phpstan-ignore unset.offset (Need to be compatible with DBAL < 4, which still has `$params['url']`) */
        unset($params['dbname'], $params['url']);
        if ($connection->get_database_platform() instanceof Postgre_Sql_Platform) {
            /** @phpstan-ignore nullCoalesce.offset (for DBAL < 4) */
            $params['dbname'] = $params['default_dbname'] ?? 'postgres';
        }
        if (!$input->get_option('force')) {
            $output->writeln('<error>ATTENTION:</error> This operation should not be executed in a production environment.');
            $output->writeln('');
            $output->writeln(sprintf('<info>Would drop the database <comment>%s</comment> for connection named <comment>%s</comment>.</info>', $name, $connection_name));
            $output->writeln('Please run the operation with --force to execute');
            $output->writeln('<error>All data will be lost!</error>');
            return self::RETURN_CODE_NO_FORCE;
        }
        // Reopen connection without database name set
        // as some vendors do not allow dropping the database connected to.
        $connection->close();
        $connection = Driver_Manager::get_connection($params, $connection->get_configuration());
        $schema_manager = $connection->create_schema_manager();
        $should_drop_database = !$if_exists || in_array($name, $schema_manager->list_databases());
        // Only quote if we don't have a path
        if (!isset($params['path'])) {
            $name = $connection->get_database_platform()->quote_single_identifier($name);
        }
        try {
            if ($should_drop_database) {
                if ($schema_manager instanceof Sq_Lite_Schema_Manager) {
                    // dropDatabase() is deprecated for Sqlite
                    $connection->close();
                    if (file_exists($name)) {
                        unlink($name);
                    }
                } else {
                    $schema_manager->drop_database($name);
                }
                $output->writeln(sprintf('<info>Dropped database <comment>%s</comment> for connection named <comment>%s</comment></info>', $name, $connection_name));
            } else {
                $output->writeln(sprintf('<info>Database <comment>%s</comment> for connection named <comment>%s</comment> doesn\'t exist. Skipped.</info>', $name, $connection_name));
            }
            return 0;
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Could not drop database <comment>%s</comment> for connection named <comment>%s</comment></error>', $name, $connection_name));
            $output->writeln(sprintf('<error>%s</error>', $e->get_message()));
            return self::RETURN_CODE_NOT_DROP;
        }
    }
}