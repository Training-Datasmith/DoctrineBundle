<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Repository;

use Doctrine\Common\Collections\Abstract_Lazy_Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\DBAL\Lock_Mode;
use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Entity_Repository;
use Doctrine\ORM\Mapping\Class_Metadata;
use Doctrine\ORM\Query\Result_Set_Mapping_Builder;
use Doctrine\ORM\Query_Builder;
use Doctrine\Persistence\Manager_Registry;
use LogicException;
use function sprintf;
/**
 * Optional EntityRepository base class with a simplified constructor (for autowiring).
 *
 * To use in your class, inject the "registry" service and call
 * the parent constructor. For example:
 *
 * class YourEntityRepository extends ServiceEntityRepository
 * {
 *     public function __construct(ManagerRegistry $registry)
 *     {
 *         parent::__construct($registry, YourEntity::class);
 *     }
 * }
 *
 * @template T of object
 * @template-extends EntityRepository<T>
 */
class Service_Entity_Repository extends Entity_Repository implements Service_Entity_Repository_Interface
{
    /** @var EntityRepository<T> */
    private Entity_Repository|null $repository = null;
    /** @param class-string<T> $entityClass The class name of the entity this repository manages */
    public function __construct(private readonly Manager_Registry $registry, private readonly string $entity_class)
    {
    }
    public function create_query_builder(string $alias, string|null $index_by = null): Query_Builder
    {
        return ($this->repository ??= $this->resolve_repository())->create_query_builder($alias, $index_by);
    }
    public function create_result_set_mapping_builder(string $alias): Result_Set_Mapping_Builder
    {
        return ($this->repository ??= $this->resolve_repository())->create_result_set_mapping_builder($alias);
    }
    public function find(mixed $id, Lock_Mode|int|null $lock_mode = null, int|null $lock_version = null): object|null
    {
        /** @psalm-suppress InvalidReturnStatement This proxy is used only in combination with newer parent class */
        return ($this->repository ??= $this->resolve_repository())->find($id, $lock_mode, $lock_version);
    }
    /**
     * {@inheritDoc}
     *
     * @psalm-suppress InvalidReturnStatement This proxy is used only in combination with newer parent class
     * @psalm-suppress InvalidReturnType This proxy is used only in combination with newer parent class
     */
    public function find_by(array $criteria, array|null $order_by = null, int|null $limit = null, int|null $offset = null): array
    {
        return ($this->repository ??= $this->resolve_repository())->find_by($criteria, $order_by, $limit, $offset);
    }
    /** {@inheritDoc} */
    public function find_one_by(array $criteria, array|null $order_by = null): object|null
    {
        /** @psalm-suppress InvalidReturnStatement This proxy is used only in combination with newer parent class */
        return ($this->repository ??= $this->resolve_repository())->find_one_by($criteria, $order_by);
    }
    /** {@inheritDoc} */
    public function count(array $criteria = []): int
    {
        return ($this->repository ??= $this->resolve_repository())->count($criteria);
    }
    /**
     * {@inheritDoc}
     */
    public function __call(string $method, array $arguments): mixed
    {
        return ($this->repository ??= $this->resolve_repository())->{$method}(...$arguments);
    }
    protected function get_entity_name(): string
    {
        return ($this->repository ??= $this->resolve_repository())->get_entity_name();
    }
    protected function get_entity_manager(): Entity_Manager_Interface
    {
        return ($this->repository ??= $this->resolve_repository())->get_entity_manager();
    }
    /** @psalm-suppress InvalidReturnType This proxy is used only in combination with newer parent class */
    protected function get_class_metadata(): Class_Metadata
    {
        /** @psalm-suppress InvalidReturnStatement This proxy is used only in combination with newer parent class */
        return ($this->repository ??= $this->resolve_repository())->get_class_metadata();
    }
    /** @phpstan-return AbstractLazyCollection<int, T>&Selectable<int, T> */
    public function matching(Criteria $criteria): Abstract_Lazy_Collection&Selectable
    {
        return ($this->repository ??= $this->resolve_repository())->matching($criteria);
    }
    /** @return EntityRepository<T> */
    private function resolve_repository(): Entity_Repository
    {
        $manager = $this->registry->get_manager_for_class($this->entity_class);
        if (!$manager instanceof Entity_Manager_Interface) {
            throw new LogicException(sprintf('Could not find the entity manager for class "%s". Check your Doctrine configuration to make sure it is configured to load this entity’s metadata.', $this->entity_class));
        }
        /** @var ClassMetadata<T> $classMetadata */
        $class_metadata = $manager->get_class_metadata($this->entity_class);
        return new Entity_Repository($manager, $class_metadata);
    }
}