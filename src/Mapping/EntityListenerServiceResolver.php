<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Mapping;

use Doctrine\ORM\Mapping\Entity_Listener_Resolver;
interface Entity_Listener_Service_Resolver extends Entity_Listener_Resolver
{
    /**
     * @param string $className
     * @param string $serviceId
     */
    // phpcs:ignore
    public function register_service($class_name, $service_id): void;
}