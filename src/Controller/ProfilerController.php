<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Controller;

use function assert;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\Oracle_Platform;
use Doctrine\DBAL\Platforms\Sq_Lite_Platform;
use Doctrine\DBAL\Platforms\Sql_Server_Platform;
use Doctrine\Persistence\Connection_Registry;
use Exception;
use Symfony\Bridge\Doctrine\Data_Collector\Doctrine_Data_Collector;
use Symfony\Component\Http_Foundation\Response;
use Symfony\Component\Http_Kernel\Profiler\Profiler;
use Symfony\Component\Var_Dumper\Cloner\Data;
use Throwable;
use Twig\Environment;
/** @internal */
class Profiler_Controller
{
    public function __construct(private readonly Environment $twig, private readonly Connection_Registry $registry, private readonly Profiler $profiler)
    {
    }
    /**
     * Renders the profiler panel for the given token.
     *
     * @param string $token The profiler token
     *
     * @return Response A Response instance
     */
    public function explain_action(string $token, string $connection_name, int $query): Response
    {
        $this->profiler->disable();
        $profile = $this->profiler->load_profile($token);
        if ($profile === null) {
            return new Response('Profile not found.', 404);
        }
        $collector = $profile->get_collector('db');
        assert($collector instanceof Doctrine_Data_Collector);
        $queries = $collector->get_queries();
        if (!isset($queries[$connection_name][$query])) {
            return new Response('This query does not exist.');
        }
        $query = $queries[$connection_name][$query];
        if (!$query['explainable']) {
            return new Response('This query cannot be explained.');
        }
        $connection = $this->registry->get_connection($connection_name);
        assert($connection instanceof Connection);
        try {
            $platform = $connection->get_database_platform();
            if ($platform instanceof Sq_Lite_Platform) {
                $results = $this->explain_sq_lite_platform($connection, $query);
            } elseif ($platform instanceof Sql_Server_Platform) {
                throw new Exception('Explain for SQLServerPlatform is currently not supported. Contributions are welcome.');
            } elseif ($platform instanceof Oracle_Platform) {
                $results = $this->explain_oracle_platform($connection, $query);
            } else {
                $results = $this->explain_other_platform($connection, $query);
            }
        } catch (Throwable) {
            return new Response('This query cannot be explained.');
        }
        return new Response($this->twig->render('@Doctrine/Collector/explain.html.twig', ['data' => $results, 'query' => $query]));
    }
    /**
     * @param mixed[] $query
     *
     * @return mixed[]
     */
    private function explain_sq_lite_platform(Connection $connection, array $query): array
    {
        $params = $query['params'];
        if ($params instanceof Data) {
            $params = $params->get_value(true);
        }
        return $connection->execute_query('EXPLAIN QUERY PLAN ' . $query['sql'], $params, $query['types'])->fetch_all_associative();
    }
    /**
     * @param mixed[] $query
     *
     * @return mixed[]
     */
    private function explain_other_platform(Connection $connection, array $query): array
    {
        $params = $query['params'];
        if ($params instanceof Data) {
            $params = $params->get_value(true);
        }
        return $connection->execute_query('EXPLAIN ' . $query['sql'], $params, $query['types'])->fetch_all_associative();
    }
    /**
     * @param mixed[] $query
     *
     * @return mixed[]
     */
    private function explain_oracle_platform(Connection $connection, array $query): array
    {
        $connection->execute_query('EXPLAIN PLAN FOR ' . $query['sql']);
        return $connection->execute_query('SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY())')->fetch_all_associative();
    }
}