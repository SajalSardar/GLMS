<?php

namespace App\Policies;

use App\Models\Chapter;
use App\Models\User;

class ChapterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chapters.view');
    }

    public function view(User $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chapters.create');
    }

    public function update(User $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.update');
    }

    public function delete(User $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.delete');
    }
}
