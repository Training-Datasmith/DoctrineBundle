<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Mapping;

use Doctrine\ORM\Mapping\Class_Metadata as OrmClassMetadata;
use Doctrine\Persistence\Mapping\Class_Metadata;
use Doctrine\Persistence\Mapping\Driver\Mapping_Driver as MappingDriverInterface;
use Psr\Container\Container_Interface;
class Mapping_Driver implements Mapping_Driver_Interface
{
    public function __construct(private readonly Mapping_Driver_Interface $driver, private readonly Container_Interface $id_generator_locator)
    {
    }
    /**
     * {@inheritDoc}
     */
    public function get_all_class_names(): array
    {
        return $this->driver->get_all_class_names();
    }
    /**
     * {@inheritDoc}
     */
    public function is_transient($class_name): bool
    {
        return $this->driver->is_transient($class_name);
    }
    /**
     * {@inheritDoc}
     */
    public function load_metadata_for_class($class_name, Class_Metadata $metadata): void
    {
        $this->driver->load_metadata_for_class($class_name, $metadata);
        if (!$metadata instanceof Orm_Class_Metadata || $metadata->generator_type !== Orm_Class_Metadata::GENERATOR_TYPE_CUSTOM || !isset($metadata->custom_generator_definition['class']) || !$this->id_generator_locator->has($metadata->custom_generator_definition['class'])) {
            return;
        }
        $id_generator = $this->id_generator_locator->get($metadata->custom_generator_definition['class']);
        $metadata->set_custom_generator_definition(['instance' => $id_generator] + $metadata->custom_generator_definition);
        $metadata->set_id_generator_type(Orm_Class_Metadata::GENERATOR_TYPE_NONE);
    }
    /**
     * Returns the inner driver
     */
    public function get_driver(): Mapping_Driver_Interface
    {
        return $this->driver;
    }
}