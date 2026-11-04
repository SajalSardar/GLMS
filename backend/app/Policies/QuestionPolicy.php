<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('questions.view');
    }

    public function view(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('questions.create');
    }

    public function update(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.update');
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.delete');
    }
}
