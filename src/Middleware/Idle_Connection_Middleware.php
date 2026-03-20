<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Middleware;

use ArrayObject;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Bridge\Doctrine\Middleware\Idle_Connection\Driver as IdleConnectionDriver;
class Idle_Connection_Middleware implements Middleware, Connection_Name_Aware_Interface
{
    private string $connection_name;
    /**
     * @param ArrayObject<string, int> $connectionExpiries
     * @param array<string, int>       $ttlByConnection
     */
    public function __construct(private readonly ArrayObject $connection_expiries, private readonly array $ttl_by_connection)
    {
    }
    public function set_connection_name(string $name): void
    {
        $this->connection_name = $name;
    }
    public function wrap(Driver $driver): Idle_Connection_Driver
    {
        return new Idle_Connection_Driver($driver, $this->connection_expiries, $this->ttl_by_connection[$this->connection_name], $this->connection_name);
    }
}