<?php

namespace App\Mcp\Concerns;

use Illuminate\Support\Str;

/**
 * Derives a tool's registry name from its class name, so the guard traits can
 * attribute an audit row without every tool passing its own name in.
 *
 * Lives in its own trait because RequiresScope and RequiresWingAccess are both
 * applied to the same tools; defining it in each would collide.
 */
trait ResolvesToolName
{
    protected function auditToolName(): string
    {
        return Str::snake(Str::beforeLast(class_basename(static::class), 'Tool'));
    }
}
