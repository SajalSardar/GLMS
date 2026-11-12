<?php

namespace App\Policies;

use App\Models\Exam;
use Illuminate\Contracts\Auth\Authenticatable;

class ExamPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function view(Authenticatable $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('exams.create');
    }

    public function update(Authenticatable $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.update');
    }

    public function delete(Authenticatable $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.delete');
    }
}
