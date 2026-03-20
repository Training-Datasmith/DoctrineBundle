<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Orm;

use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Tools\Console\Entity_Manager_Provider;
use Doctrine\Persistence\Manager_Registry;
use function get_debug_type;
use RuntimeException;
use function sprintf;
final readonly class Manager_Registry_Aware_Entity_Manager_Provider implements Entity_Manager_Provider
{
    public function __construct(private Manager_Registry $manager_registry)
    {
    }
    public function get_default_manager(): Entity_Manager_Interface
    {
        return $this->get_manager($this->manager_registry->get_default_manager_name());
    }
    public function get_manager(string $name): Entity_Manager_Interface
    {
        $em = $this->manager_registry->get_manager($name);
        if ($em instanceof Entity_Manager_Interface) {
            return $em;
        }
        throw new RuntimeException(sprintf('Only managers of type "%s" are supported. Instance of "%s given.', Entity_Manager_Interface::class, get_debug_type($em)));
    }
}