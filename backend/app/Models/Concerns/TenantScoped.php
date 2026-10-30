<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;

trait TenantScoped
{
    /**
     * Boot the trait, registering the tenant global scope. Laravel calls
     * every used trait's boot{TraitName}() method automatically.
     */
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
