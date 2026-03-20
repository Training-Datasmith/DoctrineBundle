<?php

declare(strict_types=1);

/**
 * Example: Define a Doctrine entity repository as a Symfony service.
 *
 * Symfony autowires the EntityManagerInterface; no manual registration needed
 * when using the default service configuration in DoctrineBundle.
 */

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the Product entity.
 *
 * Autowired by Symfony thanks to the ServiceEntityRepository base class.
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, \App\Entity\Product::class);
    }

    /**
     * Returns all active products ordered by name.
     *
     * @return \App\Entity\Product[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds products whose price falls within the given range.
     *
     * @return \App\Entity\Product[]
     */
    public function findByPriceRange(float $min, float $max): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.price >= :min')
            ->andWhere('p.price <= :max')
            ->setParameter('min', $min)
            ->setParameter('max', $max)
            ->orderBy('p.price', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
