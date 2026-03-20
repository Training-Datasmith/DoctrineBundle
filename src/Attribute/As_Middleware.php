<?php

declare (strict_types=1);
namespace Doctrine\Bundle\Doctrine_Bundle\Attribute;

use Attribute;
#[Attribute(Attribute::TARGET_CLASS)]
class As_Middleware
{
    /** @param string[] $connections */
    public function __construct(public array $connections = [], public int|null $priority = null)
    {
    }
}