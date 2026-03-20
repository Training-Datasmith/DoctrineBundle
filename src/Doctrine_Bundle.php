<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle;

use function assert;
use function class_exists;
use function dirname;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Cache_Schema_Subscriber_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Dbal_Schema_Filter_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Entity_Listener_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Id_Generator_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Middlewares_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Remove_Logging_Middleware_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Remove_Profiler_Controller_Pass;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Service_Repository_Compiler_Pass;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Entity_Manager_Interface;
use Symfony\Bridge\Doctrine\Dependency_Injection\Compiler_Pass\Doctrine_Validation_Pass;
use Symfony\Bridge\Doctrine\Dependency_Injection\Compiler_Pass\Register_Date_Point_Type_Pass;
use Symfony\Bridge\Doctrine\Dependency_Injection\Compiler_Pass\Register_Event_Listeners_And_Subscribers_Pass;
use Symfony\Bridge\Doctrine\Dependency_Injection\Compiler_Pass\Register_Uid_Type_Pass;
use Symfony\Bridge\Doctrine\Dependency_Injection\Security\User_Provider\Entity_Factory;
use Symfony\Bundle\Security_Bundle\Dependency_Injection\Security_Extension;
use Symfony\Component\Console\Application;
use Symfony\Component\Dependency_Injection\Compiler\Compiler_Pass_Interface;
use Symfony\Component\Dependency_Injection\Compiler\Pass_Config;
use Symfony\Component\Dependency_Injection\Container_Builder;
use Symfony\Component\Http_Kernel\Bundle\Bundle;
/** @final */
class Doctrine_Bundle extends Bundle
{
    public function build(Container_Builder $container): void
    {
        parent::build($container);
        $container->add_compiler_pass(new class implements Compiler_Pass_Interface
        {
            public function process(Container_Builder $container): void
            {
                if ($container->has('session.handler')) {
                    return;
                }
                $container->remove_definition('doctrine.orm.listeners.pdo_session_handler_schema_listener');
            }
        }, Pass_Config::TYPE_BEFORE_OPTIMIZATION);
        $container->add_compiler_pass(new Register_Event_Listeners_And_Subscribers_Pass('doctrine.connections', 'doctrine.dbal.%s_connection.event_manager', 'doctrine'), Pass_Config::TYPE_BEFORE_OPTIMIZATION);
        if ($container->has_extension('security')) {
            $security = $container->get_extension('security');
            if ($security instanceof Security_Extension) {
                $security->add_user_provider_factory(new Entity_Factory('entity', 'doctrine.orm.security.user.provider'));
            }
        }
        $container->add_compiler_pass(new Doctrine_Validation_Pass('orm'));
        $container->add_compiler_pass(new Entity_Listener_Pass());
        $container->add_compiler_pass(new Service_Repository_Compiler_Pass());
        $container->add_compiler_pass(new Id_Generator_Pass());
        $container->add_compiler_pass(new Dbal_Schema_Filter_Pass());
        $container->add_compiler_pass(new Cache_Schema_Subscriber_Pass(), Pass_Config::TYPE_BEFORE_REMOVING, -10);
        $container->add_compiler_pass(new Remove_Profiler_Controller_Pass());
        $container->add_compiler_pass(new Remove_Logging_Middleware_Pass());
        $container->add_compiler_pass(new Middlewares_Pass());
        $container->add_compiler_pass(new Register_Uid_Type_Pass());
        if (!class_exists(Register_Date_Point_Type_Pass::class)) {
            return;
        }
        $container->add_compiler_pass(new Register_Date_Point_Type_Pass());
    }
    public function shutdown(): void
    {
        if ($this->container === null) {
            return;
        }
        // Clear all entity managers to clear references to entities for GC
        if ($this->container->has_parameter('doctrine.entity_managers')) {
            foreach ($this->container->get_parameter('doctrine.entity_managers') as $id) {
                if (!$this->container->initialized($id)) {
                    continue;
                }
                $entity_manager = $this->container->get($id);
                assert($entity_manager instanceof Entity_Manager_Interface);
                $entity_manager->clear();
            }
        }
        // Close all connections to avoid reaching too many connections in the process when booting again later (tests)
        if (!$this->container->has_parameter('doctrine.connections')) {
            return;
        }
        foreach ($this->container->get_parameter('doctrine.connections') as $id) {
            if (!$this->container->initialized($id)) {
                continue;
            }
            $connection = $this->container->get($id);
            assert($connection instanceof Connection);
            $connection->close();
        }
    }
    public function register_commands(Application $application): void
    {
    }
    public function get_path(): string
    {
        return dirname(__DIR__);
    }
}