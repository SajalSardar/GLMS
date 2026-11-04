<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function view(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exams.create');
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.update');
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.delete');
    }
}
