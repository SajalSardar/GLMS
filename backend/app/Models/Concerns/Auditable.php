<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the trait, registering the creating/updating hooks. Laravel
     * calls every used trait's boot{TraitName}() method automatically.
     */
    protected static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if ($model->created_by === null) {
                $model->created_by = Auth::id();
            }
        });

        static::saving(function ($model) {
            // Set updated_by on update operations (model already exists)
            // Check if the model exists in the database by checking the primary key
            if ($model->exists) {
                $model->updated_by = Auth::id();
            }
        });
    }
}
