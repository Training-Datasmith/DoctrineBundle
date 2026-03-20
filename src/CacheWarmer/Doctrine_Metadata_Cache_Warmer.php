<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Cache_Warmer;

use Doctrine\ORM\Entity_Manager_Interface;
use function is_file;
use LogicException;
use Symfony\Bundle\Framework_Bundle\Cache_Warmer\Abstract_Php_File_Cache_Warmer;
use Symfony\Component\Cache\Adapter\Array_Adapter;
/** @internal */
final class Doctrine_Metadata_Cache_Warmer extends Abstract_Php_File_Cache_Warmer
{
    public function __construct(private readonly Entity_Manager_Interface $entity_manager, private readonly string $php_array_file)
    {
        parent::__construct($php_array_file);
    }
    public function is_optional(): bool
    {
        return true;
    }
    protected function do_warm_up(string $cache_dir, Array_Adapter $array_adapter, string|null $build_dir = null): bool
    {
        // cache already warmed up, no needs to do it again
        if (is_file($this->php_array_file)) {
            return false;
        }
        $metadata_factory = $this->entity_manager->get_metadata_factory();
        if ($metadata_factory->get_loaded_metadata()) {
            throw new LogicException('DoctrineMetadataCacheWarmer must load metadata first, check priority of your warmers.');
        }
        $metadata_factory->set_cache($array_adapter);
        $metadata_factory->get_all_metadata();
        return true;
    }
}