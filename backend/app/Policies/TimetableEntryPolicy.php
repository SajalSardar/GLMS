<?php

namespace App\Policies;

use App\Models\School;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class TimetableEntryPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function view(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.view') && $this->inScope($user, $entry->school_id, $entry->district_id);
    }

    /**
     * Create takes the target School (not yet an existing TimetableEntry),
     * so the scope check runs against where the entry would be created.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('timetable_entries.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.update') && $this->inScope($user, $entry->school_id, $entry->district_id);
    }

    public function delete(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.delete') && $this->inScope($user, $entry->school_id, $entry->district_id);
    }

    /**
     * Publishing/unpublishing a routine is as sensitive as updating it.
     */
    public function publish(User $user, TimetableEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
