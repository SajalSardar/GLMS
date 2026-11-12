<?php

namespace App\Policies;

use App\Models\Section;
use Illuminate\Contracts\Auth\Authenticatable;

class SectionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function view(Authenticatable $user, Section $section): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('sections.create');
    }

    public function update(Authenticatable $user, Section $section): bool
    {
        return $user->hasPermission('sections.update');
    }

    public function delete(Authenticatable $user, Section $section): bool
    {
        return $user->hasPermission('sections.delete');
    }
}
