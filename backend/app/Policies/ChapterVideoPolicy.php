<?php

namespace App\Policies;

use App\Models\ChapterVideo;
use App\Models\User;

class ChapterVideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chapter_videos.view');
    }

    public function view(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chapter_videos.create');
    }

    public function update(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.update');
    }

    /**
     * Reordering spans multiple rows identified only by a chapter_id in
     * the request body — there is no single ChapterVideo instance to
     * check against, so this is a no-instance ability (same shape as
     * create()), not a reuse of update(). Calling
     * $user->can('update', ChapterVideo::class) instead would pass only
     * $user to update(), which requires a second typed ChapterVideo
     * parameter with no default — an ArgumentCountError, not a graceful
     * denial.
     */
    public function reorder(User $user): bool
    {
        return $user->hasPermission('chapter_videos.update');
    }

    public function delete(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.delete');
    }
}
