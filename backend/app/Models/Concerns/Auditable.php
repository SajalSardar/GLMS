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
            if ($model->created_by === null) {
                $model->created_by = Auth::id();
            }
        });

        static::saving(function ($model) {
            // Only an update, never an insert: $model->exists is false
            // during creating() (set true only after the insert
            // completes), so this never fires on create. Only overwrite
            // updated_by when there IS an authenticated actor — an
            // unauthenticated save (console command, job, seeder re-run)
            // must not blank out a previously recorded real actor.
            if ($model->exists && ($actorId = Auth::id()) !== null) {
                $model->updated_by = $actorId;
            }
        });
    }
}
