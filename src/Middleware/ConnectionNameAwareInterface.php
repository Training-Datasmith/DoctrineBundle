<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Middleware;

interface Connection_Name_Aware_Interface
{
    public function set_connection_name(string $name): void;
}