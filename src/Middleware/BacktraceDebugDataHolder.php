<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Middleware;

use function array_slice;
use function debug_backtrace;
use const DEBUG_BACKTRACE_IGNORE_ARGS;
use function in_array;
use Symfony\Bridge\Doctrine\Middleware\Debug\Debug_Data_Holder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;
class Backtrace_Debug_Data_Holder extends Debug_Data_Holder
{
    /** @var array<string, array<int|string, mixed>[]> */
    private array $backtraces = [];
    /** @param string[] $connWithBacktraces */
    public function __construct(private readonly array $conn_with_backtraces)
    {
    }
    public function reset(): void
    {
        parent::reset();
        $this->backtraces = [];
    }
    public function add_query(string $connection_name, Query $query): void
    {
        parent::add_query($connection_name, $query);
        if (!in_array($connection_name, $this->conn_with_backtraces, true)) {
            return;
        }
        // array_slice to skip middleware calls in the trace
        $this->backtraces[$connection_name][] = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 2);
    }
    /** @return array<string, array<string, mixed>[]> */
    public function get_data(): array
    {
        $data_with_backtraces = [];
        $data = parent::get_data();
        foreach ($data as $connection_name => $data_for_conn) {
            $data_with_backtraces[$connection_name] = $this->get_data_for_connection($connection_name, $data_for_conn);
        }
        return $data_with_backtraces;
    }
    /**
     * @param mixed[][] $dataForConn
     *
     * @return mixed[][]
     */
    private function get_data_for_connection(string $connection_name, array $data_for_conn): array
    {
        $data = [];
        foreach ($data_for_conn as $idx => $record) {
            $data[] = $this->add_backtraces_if_available($connection_name, $record, $idx);
        }
        return $data;
    }
    /**
     * @param mixed[] $record
     *
     * @return mixed[]
     */
    private function add_backtraces_if_available(string $connection_name, array $record, int $idx): array
    {
        if (!isset($this->backtraces[$connection_name])) {
            return $record;
        }
        $record['backtrace'] = $this->backtraces[$connection_name][$idx];
        return $record;
    }
}