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

    /**
     * Reordering spans multiple rows identified only by a chapter_id in
     * the request body — there is no single Question instance to check
     * against, so this is a no-instance ability (same shape as create()),
     * not a reuse of update(). Calling $user->can('update',
     * Question::class) instead would pass only $user to update(), which
     * requires a second typed Question parameter with no default — an
     * ArgumentCountError, not a graceful denial. See
     * ChapterVideoPolicy::reorder() for the same pattern.
     */
    public function reorder(User $user): bool
    {
        return $user->hasPermission('questions.update');
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.delete');
    }

    /**
     * The exported CSV's correct_options column names the correct
     * answer(s) for every question — unlike on-screen viewing (where
     * QuestionOptionResource strips is_correct), there is no way to
     * redact the answer key from a downloaded file. questions.view is
     * granted to students and other non-management roles, so exporting
     * must gate on questions.update instead, same as reorder().
     */
    public function export(User $user): bool
    {
        return $user->hasPermission('questions.update');
    }
}
