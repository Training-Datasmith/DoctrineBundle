<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Mapping;

use function assert;
use Doctrine\ORM\Id\Abstract_Id_Generator;
use Doctrine\ORM\Mapping\Class_Metadata;
use Doctrine\ORM\Mapping\Class_Metadata_Factory as BaseClassMetadataFactory;
class Class_Metadata_Factory extends Base_Class_Metadata_Factory
{
    /**
     * {@inheritDoc}
     *
     * @param ClassMetadata<object> $class
     * @param ClassMetadata<object> $parent
     */
    protected function do_load_metadata($class, $parent, $root_entity_found, array $non_superclass_parents): void
    {
        parent::do_load_metadata($class, $parent, $root_entity_found, $non_superclass_parents);
        $custom_generator_definition = $class->custom_generator_definition;
        if (!isset($custom_generator_definition['instance'])) {
            return;
        }
        /** @phpstan-ignore function.impossibleType, instanceof.alwaysFalse */
        assert($custom_generator_definition['instance'] instanceof Abstract_Id_Generator);
        $class->set_id_generator_type(Class_Metadata::GENERATOR_TYPE_CUSTOM);
        $class->set_id_generator($custom_generator_definition['instance']);
        unset($custom_generator_definition['instance']);
        $class->set_custom_generator_definition($custom_generator_definition);
    }
}