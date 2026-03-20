<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Data_Collector;

use function array_map;
use function array_sum;
use function arsort;
use function assert;
use function count;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Cache\Cache_Configuration;
use Doctrine\ORM\Cache\Logging\Cache_Logger_Chain;
use Doctrine\ORM\Cache\Logging\Statistics_Cache_Logger;
use Doctrine\ORM\Entity_Manager_Interface;
use Doctrine\ORM\Tools\Schema_Validator;
use Doctrine\Persistence\Manager_Registry;
use Symfony\Bridge\Doctrine\Data_Collector\Doctrine_Data_Collector as BaseCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\Debug_Data_Holder;
use Symfony\Component\Http_Foundation\Request;
use Symfony\Component\Http_Foundation\Response;
use Throwable;
use function usort;
/**
 * @phpstan-type DataType = array{
 *    caches: array{
 *       enabled: bool,
 *       counts: array<"puts"|"hits"|"misses", int>,
 *       log_enabled: bool,
 *       regions: array<"puts"|"hits"|"misses", array<string, int>>,
 *    },
 *    connections: list<string>,
 *    entities: array<string, array<class-string, array{class: class-string, file: false|string, line: false|int}>>,
 *    errors: array<string, array<class-string, list<string>>>,
 *    managers: list<string>,
 *    queries: array<string, list<array{
 *       executionMS: float,
 *       explainable: bool,
 *       sql: string,
 *       params: ?array<array-key, mixed>,
 *       runnable: bool,
 *       types: ?array<array-key, Type|int|string|null>
 *    }>>,
 *    entityCounts: array<string, array<class-string, int>>
 * }
 * @phpstan-type GroupedQueryItemType = array{
 *    executionMS: float,
 *    explainable: bool,
 *    sql: string,
 *    params: ?array<array-key, mixed>,
 *    runnable: bool,
 *    types: ?array<array-key, Type|int|string|null>,
 *    count: int,
 *    index: int,
 *    executionPercent?: float
 * }
 * @phpstan-type GroupedQueriesType = array<string, array<int, GroupedQueryItemType>>
 * @phpstan-property DataType $data
 */
class Doctrine_Data_Collector extends Base_Collector
{
    private int|null $invalid_entity_count = null;
    private int|null $managed_entity_count = null;
    /** @var GroupedQueriesType|null */
    private array|null $grouped_queries = null;
    public function __construct(private readonly Manager_Registry $registry, private readonly bool $should_validate_schema = true, Debug_Data_Holder|null $debug_data_holder = null)
    {
        if ($debug_data_holder === null) {
            $debug_data_holder = new Debug_Data_Holder();
        }
        parent::__construct($registry, $debug_data_holder);
    }
    public function collect(Request $request, Response $response, Throwable|null $exception = null): void
    {
        parent::collect($request, $response, $exception);
        $errors = [];
        $entities = [];
        $entity_counts = [];
        $caches = ['enabled' => false, 'log_enabled' => false, 'counts' => ['puts' => 0, 'hits' => 0, 'misses' => 0], 'regions' => ['puts' => [], 'hits' => [], 'misses' => []]];
        foreach ($this->registry->get_managers() as $name => $em) {
            assert($em instanceof Entity_Manager_Interface);
            if ($this->should_validate_schema) {
                $entities[$name] = [];
                $factory = $em->get_metadata_factory();
                $validator = new Schema_Validator($em);
                foreach ($factory->get_loaded_metadata() as $class) {
                    if (isset($entities[$name][$class->get_name()])) {
                        continue;
                    }
                    $class_errors = $validator->validate_class($class);
                    $r = $class->get_reflection_class();
                    $entities[$name][$class->get_name()] = ['class' => $class->get_name(), 'file' => $r->get_file_name(), 'line' => $r->get_start_line()];
                    if (empty($class_errors)) {
                        continue;
                    }
                    $errors[$name][$class->get_name()] = $class_errors;
                }
            }
            $entity_counts[$name] = [];
            foreach ($em->get_unit_of_work()->get_identity_map() as $class_name => $entity_list) {
                $entity_counts[$name][$class_name] = count($entity_list);
            }
            // Sort entities by count (in descending order)
            arsort($entity_counts[$name]);
            $em_config = $em->get_configuration();
            $slc_enabled = $em_config->is_second_level_cache_enabled();
            if (!$slc_enabled) {
                continue;
            }
            $caches['enabled'] = true;
            $cache_configuration = $em_config->get_second_level_cache_configuration();
            assert($cache_configuration instanceof Cache_Configuration);
            $cache_logger_chain = $cache_configuration->get_cache_logger();
            assert($cache_logger_chain instanceof Cache_Logger_Chain || $cache_logger_chain === null);
            if (!$cache_logger_chain) {
                continue;
            }
            if (!$cache_logger_chain->get_logger('statistics')) {
                continue;
            }
            $cache_logger_stats = $cache_logger_chain->get_logger('statistics');
            assert($cache_logger_stats instanceof Statistics_Cache_Logger);
            $caches['log_enabled'] = true;
            $caches['counts']['puts'] += $cache_logger_stats->get_put_count();
            $caches['counts']['hits'] += $cache_logger_stats->get_hit_count();
            $caches['counts']['misses'] += $cache_logger_stats->get_miss_count();
            foreach ($cache_logger_stats->get_regions_put() as $key => $value) {
                if (!isset($caches['regions']['puts'][$key])) {
                    $caches['regions']['puts'][$key] = 0;
                }
                $caches['regions']['puts'][$key] += $value;
            }
            foreach ($cache_logger_stats->get_regions_hit() as $key => $value) {
                if (!isset($caches['regions']['hits'][$key])) {
                    $caches['regions']['hits'][$key] = 0;
                }
                $caches['regions']['hits'][$key] += $value;
            }
            foreach ($cache_logger_stats->get_regions_miss() as $key => $value) {
                if (!isset($caches['regions']['misses'][$key])) {
                    $caches['regions']['misses'][$key] = 0;
                }
                $caches['regions']['misses'][$key] += $value;
            }
        }
        $this->data['entities'] = $entities;
        $this->data['errors'] = $errors;
        $this->data['caches'] = $caches;
        $this->data['entityCounts'] = $entity_counts;
        $this->grouped_queries = null;
    }
    /** @return array<string, array<class-string, array{class: class-string, file: false|string, line: false|int}>> */
    public function get_entities(): array
    {
        return $this->data['entities'];
    }
    /** @return array<string, array<string, list<string>>> */
    public function get_mapping_errors(): array
    {
        return $this->data['errors'];
    }
    public function get_cache_hits_count(): int
    {
        return $this->data['caches']['counts']['hits'];
    }
    public function get_cache_puts_count(): int
    {
        return $this->data['caches']['counts']['puts'];
    }
    public function get_cache_misses_count(): int
    {
        return $this->data['caches']['counts']['misses'];
    }
    public function get_cache_enabled(): bool
    {
        return $this->data['caches']['enabled'];
    }
    /**
     * @return array<string, array<string, int>>
     * @phpstan-return array<"puts"|"hits"|"misses", array<string, int>>
     */
    public function get_cache_regions(): array
    {
        return $this->data['caches']['regions'];
    }
    /** @return array<string, int> */
    public function get_cache_counts(): array
    {
        return $this->data['caches']['counts'];
    }
    public function get_invalid_entity_count(): int
    {
        return $this->invalid_entity_count ??= array_sum(array_map(count(...), $this->data['errors']));
    }
    public function get_managed_entity_count(): int
    {
        if ($this->managed_entity_count === null) {
            $total = 0;
            foreach ($this->data['entityCounts'] as $entities) {
                $total += array_sum($entities);
            }
            $this->managed_entity_count = $total;
        }
        return $this->managed_entity_count;
    }
    /** @return array<string, array<class-string, int>> */
    public function get_managed_entity_count_by_class(): array
    {
        return $this->data['entityCounts'];
    }
    /**
     * @return string[][]
     * @phpstan-return GroupedQueriesType
     */
    public function get_grouped_queries(): array
    {
        if ($this->grouped_queries !== null) {
            return $this->grouped_queries;
        }
        $this->grouped_queries = [];
        $total_execution_ms = 0;
        foreach ($this->data['queries'] as $connection => $queries) {
            $connection_grouped_queries = [];
            foreach ($queries as $i => $query) {
                $key = $query['sql'];
                if (!isset($connection_grouped_queries[$key])) {
                    $connection_grouped_queries[$key] = $query;
                    $connection_grouped_queries[$key]['executionMS'] = 0;
                    $connection_grouped_queries[$key]['count'] = 0;
                    $connection_grouped_queries[$key]['index'] = $i;
                    // "Explain query" relies on query index in 'queries'.
                }
                $connection_grouped_queries[$key]['executionMS'] += $query['executionMS'];
                $connection_grouped_queries[$key]['count']++;
                $total_execution_ms += $query['executionMS'];
            }
            usort($connection_grouped_queries, static fn(array $a, array $b) => $b['executionMS'] <=> $a['executionMS']);
            $this->grouped_queries[$connection] = $connection_grouped_queries;
        }
        foreach ($this->grouped_queries as &$queries) {
            foreach ($queries as &$query) {
                $query['executionPercent'] = $this->execution_time_percentage($query['executionMS'], $total_execution_ms);
            }
        }
        return $this->grouped_queries;
    }
    private function execution_time_percentage(float $execution_time_ms, float $total_execution_time_ms): float
    {
        if (!$total_execution_time_ms) {
            return 0;
        }
        return $execution_time_ms / $total_execution_time_ms * 100;
    }
    public function get_grouped_query_count(): int
    {
        $count = 0;
        foreach ($this->get_grouped_queries() as $connection_grouped_queries) {
            $count += count($connection_grouped_queries);
        }
        return $count;
    }
}