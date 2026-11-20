<?php

namespace App\Policies;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamTermPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_terms.view');
    }

    public function view(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.view');
    }

    /**
     * national_admin only — the exam-term calendar is national, not
     * per-school, same reasoning as HolidayPolicy.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_terms.create');
    }

    public function update(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.update');
    }

    public function delete(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.delete');
    }
}
