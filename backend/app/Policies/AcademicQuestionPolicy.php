<?php

namespace App\Policies;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicQuestionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_questions.view');
    }

    public function view(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_questions.create');
    }

    public function update(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.update');
    }

    public function delete(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.delete');
    }
}
