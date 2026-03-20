<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Attribute;

use Attribute;
/**
 * Service tag to autoconfigure event listeners.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class As_Doctrine_Listener
{
    public function __construct(public string $event, public int|null $priority = null, public string|null $connection = null)
    {
    }
}