<?php

namespace App\Policies;

use App\Models\TimetableEntry;
use App\Models\User;

class TimetableEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function view(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('timetable_entries.create');
    }

    public function update(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.update');
    }

    public function delete(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.delete');
    }

    /**
     * Publishing/unpublishing a routine is as sensitive as updating it.
     */
    public function publish(User $user, TimetableEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
