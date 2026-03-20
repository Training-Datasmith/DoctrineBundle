<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Command;

use function assert;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\Manager_Registry;
use Symfony\Component\Console\Command\Command;
/**
 * Base class for Doctrine console commands to extend from.
 *
 * @internal
 */
abstract class Doctrine_Command extends Command
{
    public function __construct(private readonly Manager_Registry $doctrine)
    {
        parent::__construct();
    }
    /**
     * Get a doctrine dbal connection by symfony name.
     */
    protected function get_doctrine_connection(string $name): Connection
    {
        $connection = $this->get_doctrine()->get_connection($name);
        assert($connection instanceof Connection);
        return $connection;
    }
    protected function get_doctrine(): Manager_Registry
    {
        return $this->doctrine;
    }
}