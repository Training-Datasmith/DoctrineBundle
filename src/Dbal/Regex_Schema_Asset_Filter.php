<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dbal;

use Doctrine\DBAL\Schema\Abstract_Asset;
use Doctrine\DBAL\Schema\Name;
use InvalidArgumentException;
use function preg_match;
class Regex_Schema_Asset_Filter
{
    public function __construct(private readonly string $filter_expression)
    {
        if (@preg_match($filter_expression, '') === false) {
            throw new InvalidArgumentException(sprintf('Invalid regex pattern supplied for schema_filter: "%s"', $filter_expression));
        }
    }
    /** @param string|AbstractAsset<Name> $assetName */
    public function __invoke(string|Abstract_Asset $asset_name): bool
    {
        if ($asset_name instanceof Abstract_Asset) {
            $asset_name = $asset_name->get_name();
        }
        return (bool) preg_match($this->filter_expression, $asset_name);
    }
}