<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Bridge\Doctrine\Middleware\Debug\Debug_Data_Holder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Driver;
use Symfony\Component\Stopwatch\Stopwatch;
class Debug_Middleware implements Middleware, Connection_Name_Aware_Interface
{
    private string $connection_name = 'default';
    public function __construct(private readonly Debug_Data_Holder $debug_data_holder, private readonly Stopwatch|null $stopwatch)
    {
    }
    public function set_connection_name(string $name): void
    {
        $this->connection_name = $name;
    }
    public function wrap(Driver_Interface $driver): Driver_Interface
    {
        return new Driver($driver, $this->debug_data_holder, $this->stopwatch, $this->connection_name);
    }
}