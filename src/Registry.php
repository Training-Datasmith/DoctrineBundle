<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle;

use function assert;
use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\Persistence\Proxy;
use function method_exists;
use ReflectionClass;
use Symfony\Bridge\Doctrine\Manager_Registry;
use Symfony\Component\Dependency_Injection\Container;
use Symfony\Component\Var_Exporter\Lazy_Object_Interface;
use Symfony\Contracts\Service\Reset_Interface;
/**
 * References all Doctrine connections and entity managers in a given Container.
 */
class Registry extends Manager_Registry implements Reset_Interface
{
    /**
     * @param string[] $connections
     * @param string[] $entityManagers
     */
    public function __construct(Container $container, array $connections, array $entity_managers, string $default_connection, string $default_entity_manager)
    {
        $this->container = $container;
        parent::__construct('ORM', $connections, $entity_managers, $default_connection, $default_entity_manager, Proxy::class);
    }
    public function reset(): void
    {
        foreach ($this->get_manager_names() as $manager_name => $service_id) {
            $this->reset_or_clear_manager($manager_name, $service_id);
        }
    }
    private function reset_or_clear_manager(string $manager_name, string $service_id): void
    {
        if (!$this->container->initialized($service_id)) {
            return;
        }
        $manager = $this->container->get($service_id);
        assert($manager instanceof Entity_Manager_Interface);
        // Determine if the version of symfony/dependency-injection is >= 7.3
        /** @phpstan-ignore function.alreadyNarrowedType */
        $sf_native_lazy_objects = method_exists('Symfony\Component\DependencyInjection\ContainerBuilder', 'findTaggedResourceIds');
        if (!$sf_native_lazy_objects) {
            if (!$manager instanceof Lazy_Object_Interface || $manager->is_open()) {
                $manager->clear();
                return;
            }
        } else {
            $r = new ReflectionClass($manager);
            if ($r->is_uninitialized_lazy_object($manager)) {
                return;
            }
            if ($manager->is_open()) {
                $manager->clear();
                return;
            }
        }
        $this->reset_manager($manager_name);
    }
}