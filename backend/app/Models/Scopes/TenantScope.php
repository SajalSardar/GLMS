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
        $user = Auth::user();

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
