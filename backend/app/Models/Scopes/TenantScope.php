<?php

namespace App\Models\Scopes;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Re-entrancy guard against infinite recursion: resolving the
     * currently-authenticating actor (Auth::user()) can itself require
     * querying a TenantScoped model — e.g. Sanctum loading a bearer
     * token's `tokenable` row, which is a query against User/TeacherUser/
     * etc. If THAT query also goes through this scope, it calls
     * Auth::user() again to decide how to scope itself, which re-enters
     * the SAME in-flight guard resolution Sanctum is mid-way through —
     * unbounded recursion, confirmed to exhaust PHP's memory limit.
     * The re-entrant call is, by definition, the query resolving the
     * actor's own identity — it cannot be scoped by an identity that
     * isn't resolved yet, so it must run unscoped, exactly like the
     * explicit withoutGlobalScope() bypasses already used elsewhere in
     * this codebase for the same reason (see e.g. UserController::store()'s
     * trashed-row lookup).
     */
    private static bool $resolvingActor = false;

    /**
     * Restrict the query to the authenticated actor's tenant: a
     * school-scoped actor sees only their own school's rows (or, for
     * School itself, only their own school row); a district-scoped actor
     * (no school_id) sees only their own district's rows; an unscoped
     * actor (e.g. national_admin) is unrestricted. An unauthenticated
     * request is also unrestricted, so the existing public index/show
     * routes keep working unchanged.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (self::$resolvingActor) {
            return;
        }

        self::$resolvingActor = true;

        try {
            $user = Auth::user();
        } finally {
            self::$resolvingActor = false;
        }

        if ($user === null) {
            return;
        }

        if ($user->school_id !== null) {
            $column = $model instanceof School ? 'id' : 'school_id';
            $builder->where($model->getTable().'.'.$column, $user->school_id);

            return;
        }

        if ($user->district_id !== null) {
            $builder->where($model->getTable().'.district_id', $user->district_id);
        }
    }
}
