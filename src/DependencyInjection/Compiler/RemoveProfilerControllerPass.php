<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler;

use Doctrine\Bundle\Doctrine_Bundle\Controller\Profiler_Controller;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Container_Builder;
/** @internal */
final class Remove_Profiler_Controller_Pass implements Compiler_Pass_Interface
{
    public function process(Container_Builder $container): void
    {
        if ($container->has('twig') && $container->has('profiler')) {
            return;
        }
        $container->remove_definition(Profiler_Controller::class);
    }
}