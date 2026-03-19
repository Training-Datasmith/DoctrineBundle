<?php

declare(strict_types=1);

namespace Doctrine\Bundle\DoctrineBundle\Dbal;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Name;
use InvalidArgumentException;

use function preg_match;

class RegexSchemaAssetFilter
{
    public function __construct(
        private readonly string $filterExpression,
    ) {
        if (@preg_match($filterExpression, '') === false) {
            throw new InvalidArgumentException(
                sprintf('Invalid regex pattern supplied for schema_filter: "%s"', $filterExpression),
            );
        }
    }

    /** @param string|AbstractAsset<Name> $assetName */
    public function __invoke(string|AbstractAsset $assetName): bool
    {
        if ($assetName instanceof AbstractAsset) {
            $assetName = $assetName->getName();
        }

        return (bool) preg_match($this->filterExpression, $assetName);
    }
}
