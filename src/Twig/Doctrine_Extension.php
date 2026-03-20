<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Twig;

use function addslashes;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function assert;
use function bin2hex;
use function count;
use Doctrine\Sql_Formatter\Html_Highlighter;
use Doctrine\Sql_Formatter\Null_Highlighter;
use Doctrine\Sql_Formatter\Sql_Formatter;
use function implode;
use function is_array;
use function is_bool;
use function is_string;
use function preg_last_error;
use function preg_match;
use const PREG_NO_ERROR;
use function preg_replace_callback;
use RuntimeException;
use function sprintf;
use Stringable;
use function strtoupper;
use function substr;
use Symfony\Component\Var_Dumper\Cloner\Data;
use Twig\Extension\Abstract_Extension;
use Twig\Twig_Filter;
/**
 * This class contains the needed functions in order to do the query highlighting
 *
 * @internal
 */
class Doctrine_Extension extends Abstract_Extension
{
    private Sql_Formatter $sql_formatter;
    /**
     * Define our functions
     *
     * @return TwigFilter[]
     */
    public function get_filters(): array
    {
        return [new Twig_Filter('doctrine_prettify_sql', $this->prettify_sql(...), ['is_safe' => ['html']]), new Twig_Filter('doctrine_format_sql', $this->format_sql(...), ['is_safe' => ['html']]), new Twig_Filter('doctrine_replace_query_parameters', $this->replace_query_parameters(...))];
    }
    /**
     * Escape parameters of a SQL query
     * DON'T USE THIS FUNCTION OUTSIDE ITS INTENDED SCOPE
     *
     * @internal
     */
    public static function escape_function(mixed $parameter): string|int|float
    {
        $result = $parameter;
        switch (true) {
            // Check if result is non-unicode string using PCRE_UTF8 modifier
            case is_string($result) && !preg_match('//u', $result):
                $result = '0x' . strtoupper(bin2hex($result));
                break;
            case is_string($result):
                $result = "'" . addslashes($result) . "'";
                break;
            case is_array($result):
                foreach ($result as &$value) {
                    $value = static::escape_function($value);
                }
                $result = implode(', ', $result) ?: 'NULL';
                break;
            case $result instanceof Stringable:
                $result = "'" . addslashes((string) $result) . "'";
                break;
            case $result === null:
                $result = 'NULL';
                break;
            case is_bool($result):
                $result = $result ? '1' : '0';
                break;
        }
        return $result;
    }
    /**
     * Return a query with the parameters replaced
     *
     * @param array<array-key, mixed>|Data $parameters
     */
    public function replace_query_parameters(string $query, array|Data $parameters): string
    {
        if ($parameters instanceof Data) {
            $parameters = $parameters->get_value(true);
            assert(is_array($parameters));
        }
        $keys = array_keys($parameters);
        if (count(array_filter($keys, is_int(...))) === count($keys)) {
            $parameters = array_values($parameters);
        }
        $i = 0;
        $result = preg_replace_callback('/(?<!\?)\?(?!\?)|(?<!:)(:[a-z0-9_]+)/i', static function (array $matches) use ($parameters, &$i): string {
            $key = substr($matches[0], 1);
            if (!array_key_exists($i, $parameters) && !array_key_exists($key, $parameters)) {
                return $matches[0];
            }
            $value = array_key_exists($i, $parameters) ? $parameters[$i] : $parameters[$key];
            $i++;
            return (string) Doctrine_Extension::escape_function($value);
        }, $query);
        $preg_error = preg_last_error();
        if ($preg_error !== PREG_NO_ERROR) {
            throw new RuntimeException(sprintf('Failed to replace query parameters: PCRE error %d', $preg_error));
        }
        if ($result === null) {
            throw new RuntimeException('Failed to replace query parameters: unexpected null result');
        }
        return $result;
    }
    public function prettify_sql(string $sql): string
    {
        $this->set_up_sql_formatter();
        return $this->sql_formatter->highlight($sql);
    }
    public function format_sql(string $sql, bool $highlight): string
    {
        $this->set_up_sql_formatter($highlight);
        return $this->sql_formatter->format($sql);
    }
    private function set_up_sql_formatter(bool $highlight = true): void
    {
        $this->sql_formatter = new Sql_Formatter($highlight ? new Html_Highlighter([Html_Highlighter::HIGHLIGHT_PRE => 'class="highlight highlight-sql"', Html_Highlighter::HIGHLIGHT_QUOTE => 'class="string"', Html_Highlighter::HIGHLIGHT_BACKTICK_QUOTE => 'class="string"', Html_Highlighter::HIGHLIGHT_RESERVED => 'class="keyword"', Html_Highlighter::HIGHLIGHT_BOUNDARY => 'class="symbol"', Html_Highlighter::HIGHLIGHT_NUMBER => 'class="number"', Html_Highlighter::HIGHLIGHT_WORD => 'class="word"', Html_Highlighter::HIGHLIGHT_ERROR => 'class="error"', Html_Highlighter::HIGHLIGHT_COMMENT => 'class="comment"', Html_Highlighter::HIGHLIGHT_VARIABLE => 'class="variable"']) : new Null_Highlighter());
    }
}