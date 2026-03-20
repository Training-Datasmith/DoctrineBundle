<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dbal;

use function assert;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\Console\Connection_Provider;
use Doctrine\Persistence\Abstract_Manager_Registry;
class Manager_Registry_Aware_Connection_Provider implements Connection_Provider
{
    public function __construct(private readonly Abstract_Manager_Registry $manager_registry)
    {
    }
    public function get_default_connection(): Connection
    {
        $connection = $this->manager_registry->get_connection();
        assert($connection instanceof Connection);
        return $connection;
    }
    public function get_connection(string $name): Connection
    {
        $connection = $this->manager_registry->get_connection($name);
        assert($connection instanceof Connection);
        return $connection;
    }
}