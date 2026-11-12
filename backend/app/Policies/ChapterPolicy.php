<?php

namespace App\Policies;

use App\Models\Chapter;
use Illuminate\Contracts\Auth\Authenticatable;

class ChapterPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('chapters.view');
    }

    public function view(Authenticatable $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('chapters.create');
    }

    public function update(Authenticatable $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.update');
    }

    public function delete(Authenticatable $user, Chapter $chapter): bool
    {
        return $user->hasPermission('chapters.delete');
    }
}
