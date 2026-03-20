<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
/** @internal */
final class Remove_Logging_Middleware_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        if ($container->has('logger')) {
            return;
        }
        $container->remove_definition('doctrine.dbal.logging_middleware');
    }
}