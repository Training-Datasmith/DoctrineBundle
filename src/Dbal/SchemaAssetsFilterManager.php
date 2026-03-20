<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Dbal;

use Doctrine\DBAL\Schema\Abstract_Asset;
use Doctrine\DBAL\Schema\Name;
/**
 * Manages schema filters passed to Connection::setSchemaAssetsFilter()
 */
class Schema_Assets_Filter_Manager
{
    /** @param callable[] $schemaAssetFilters */
    public function __construct(private readonly array $schema_asset_filters)
    {
    }
    /** @param string|AbstractAsset<Name> $assetName */
    public function __invoke(string|Abstract_Asset $asset_name): bool
    {
        foreach ($this->schema_asset_filters as $schema_asset_filter) {
            if ($schema_asset_filter($asset_name) === false) {
                return false;
            }
        }
        return true;
    }
}