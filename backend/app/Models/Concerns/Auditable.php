<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the trait, registering the creating/saving hooks. Laravel
     * calls every used trait's boot{TraitName}() method automatically.
     *
     * Deliberately hooks `saving`, not `updating`: Eloquent's save()
     * checks $this->isDirty() *before* calling performUpdate() (which is
     * what fires `updating`), so a no-op update (e.g. update([]) with no
     * attribute changes) never reaches `updating` at all. `saving` fires
     * unconditionally, before that dirty check, so setting updated_by
     * here re-dirties the model and forces the real UPDATE through.
     */
    protected static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if ($model->created_by_id === null) {
                $actor = Auth::user();

                if ($actor !== null) {
                    $model->created_by_type = get_class($actor);
                    $model->created_by_id = $actor->getAuthIdentifier();
                }
            }
        });

        static::saving(function ($model) {
            // Only an update, never an insert: $model->exists is false
            // during creating() (set true only after the insert
            // completes), so this never fires on create.
            if (! $model->exists) {
                return;
            }

            $actor = Auth::user();

            if ($actor !== null) {
                $model->updated_by_type = get_class($actor);
                $model->updated_by_id = $actor->getAuthIdentifier();
            }
        });
    }
}
