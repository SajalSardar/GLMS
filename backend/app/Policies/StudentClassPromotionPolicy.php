<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class StudentClassPromotionPolicy
{
    /**
     * The one ability this whole feature needs — gates both the
     * read-only preview and the actual promotion. school_admin/
     * national_admin only; no teacher exception of any kind, unlike
     * every per-subject/per-section ability elsewhere in this feature.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('student_promotions.create');
    }
}
