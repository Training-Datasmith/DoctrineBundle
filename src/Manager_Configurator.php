<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle;

use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Query\Filter\Sql_Filter;
/**
 * Configurator for an EntityManager
 */
class Manager_Configurator
{
    /**
     * @param string[]                           $enabledFilters
     * @param array<string,array<string,string>> $filtersParameters
     */
    public function __construct(private readonly array $enabled_filters = [], private readonly array $filters_parameters = [])
    {
    }
    /**
     * Create a connection by name.
     */
    public function configure(Entity_Manager_Interface $entity_manager): void
    {
        $this->enable_filters($entity_manager);
    }
    /**
     * Enables filters for a given entity manager
     */
    private function enable_filters(Entity_Manager_Interface $entity_manager): void
    {
        if (empty($this->enabled_filters)) {
            return;
        }
        $filter_collection = $entity_manager->get_filters();
        foreach ($this->enabled_filters as $filter) {
            $this->set_filter_parameters($filter, $filter_collection->enable($filter));
        }
    }
    /**
     * Sets default parameters for a given filter
     */
    private function set_filter_parameters(string $name, Sql_Filter $filter): void
    {
        if (empty($this->filters_parameters[$name])) {
            return;
        }
        $parameters = $this->filters_parameters[$name];
        foreach ($parameters as $param_name => $param_value) {
            $filter->set_parameter($param_name, $param_value);
        }
    }
}