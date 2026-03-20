<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Repository;

use function class_exists;
use Doctrine\Bundle\Doctrine_Bundle\Dependency_Injection\Compiler\Service_Repository_Compiler_Pass;
use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Entity_Repository;
use Doctrine\ORM\Mapping\Class_Metadata;
use Doctrine\ORM\Repository\Repository_Factory;
use Doctrine\Persistence\Object_Repository;
use function get_debug_type;
use function is_a;
use Psr\Container\Container_Interface;
use RuntimeException;
use function spl_object_hash;
use function sprintf;
/**
 * Fetches repositories from the container or falls back to normal creation.
 *
 * @internal
 */
final class Container_Repository_Factory implements Repository_Factory
{
    /** @var array<string, ObjectRepository<object>> */
    private array $managed_repositories = [];
    /** @param ContainerInterface $container A service locator containing the repositories */
    public function __construct(private readonly Container_Interface $container)
    {
    }
    /**
     * Gets the repository for an entity class.
     *
     * @param class-string<T> $entityName
     *
     * @return EntityRepository<T>
     *
     * @template T of object
     */
    public function get_repository(Entity_Manager_Interface $entity_manager, string $entity_name): Entity_Repository
    {
        $metadata = $entity_manager->get_class_metadata($entity_name);
        $repository_service_id = $metadata->custom_repository_class_name;
        $custom_repository_name = $metadata->custom_repository_class_name;
        if ($custom_repository_name !== null) {
            // fetch from the container
            if ($this->container->has($custom_repository_name)) {
                $repository = $this->container->get($custom_repository_name);
                if (!$repository instanceof Entity_Repository) {
                    throw new RuntimeException(sprintf('The service "%s" must extend EntityRepository (e.g. by extending ServiceEntityRepository), "%s" given.', $repository_service_id, get_debug_type($repository)));
                }
                /** @phpstan-var EntityRepository<T> */
                return $repository;
            }
            // if not in the container but the class/id implements the interface, throw an error
            if (is_a($custom_repository_name, Service_Entity_Repository_Interface::class, true)) {
                throw new RuntimeException(sprintf('The "%s" entity repository implements "%s", but its service could not be found. Make sure the service exists and is tagged with "%s".', $custom_repository_name, Service_Entity_Repository_Interface::class, Service_Repository_Compiler_Pass::REPOSITORY_SERVICE_TAG));
            }
            if (!class_exists($custom_repository_name)) {
                throw new RuntimeException(sprintf('The "%s" entity has a repositoryClass set to "%s", but this is not a valid class. Check your class naming. If this is meant to be a service id, make sure this service exists and is tagged with "%s".', $metadata->name, $custom_repository_name, Service_Repository_Compiler_Pass::REPOSITORY_SERVICE_TAG));
            }
            // allow the repository to be created below
        }
        return $this->get_or_create_repository($entity_manager, $metadata);
    }
    /**
     * @param ClassMetadata<TEntity> $metadata
     *
     * @return EntityRepository<TEntity>
     *
     * @template TEntity of object
     */
    private function get_or_create_repository(Entity_Manager_Interface $entity_manager, Class_Metadata $metadata): Entity_Repository
    {
        $repository_hash = $metadata->get_name() . spl_object_hash($entity_manager);
        if (isset($this->managed_repositories[$repository_hash])) {
            /** @phpstan-var EntityRepository<TEntity> */
            return $this->managed_repositories[$repository_hash];
        }
        $repository_class_name = $metadata->custom_repository_class_name ?: $entity_manager->get_configuration()->get_default_repository_class_name();
        /** @phpstan-var EntityRepository<TEntity> */
        return $this->managed_repositories[$repository_hash] = new $repository_class_name($entity_manager, $metadata);
    }
}