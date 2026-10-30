<?php

namespace App\Policies;

use App\Models\School;
use App\Models\Section;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class SectionPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function view(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.view') && $this->inScope($user, $section->school_id, $section->district_id);
    }

    /**
     * Create takes the target School (not yet an existing Section), so
     * the scope check runs against where the section would be created.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('sections.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.update') && $this->inScope($user, $section->school_id, $section->district_id);
    }

    public function delete(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.delete') && $this->inScope($user, $section->school_id, $section->district_id);
    }
}
