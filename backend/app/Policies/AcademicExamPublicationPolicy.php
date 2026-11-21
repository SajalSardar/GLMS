<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamPublicationPolicy
{
    /**
     * Viewing publish status is the same academic_exam_marks.view
     * permission that gates marks auditing — no separate permission for
     * "can see whether a section is published yet".
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_marks.view');
    }

    /**
     * Publishing is school_admin/national_admin only — never a teacher,
     * regardless of their section/subject relationship.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_marks.publish');
    }
}
