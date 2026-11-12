<?php

namespace App\Policies;

use App\Models\TimetableEntry;
use Illuminate\Contracts\Auth\Authenticatable;

class TimetableEntryPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function view(Authenticatable $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('timetable_entries.create');
    }

    public function update(Authenticatable $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.update');
    }

    public function delete(Authenticatable $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.delete');
    }

    /**
     * Publishing/unpublishing a routine is as sensitive as updating it.
     */
    public function publish(Authenticatable $user, TimetableEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    /**
     * A no-instance ability (same convention as ClassVersionPolicy's
     * updateSubjects()/ChapterVideoPolicy's reorder()): generating
     * replaces entries across every section in a shift at once, gated
     * independently of create() — it is at least as sensitive.
     */
    public function generate(Authenticatable $user): bool
    {
        return $user->hasPermission('timetable_entries.generate');
    }
}
