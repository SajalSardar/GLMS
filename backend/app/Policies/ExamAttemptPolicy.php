<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    /**
     * Viewing a single attempt: the attempt's own student, their linked
     * guardian, or a management/oversight role holding exam_attempts.view.
     * No flat permission is required for the first two — same "own
     * record" precedent as ChapterVideoController::updateProgress().
     */
    public function view(User $user, ExamAttempt $examAttempt): bool
    {
        if ($user->id === $examAttempt->user_id) {
            return true;
        }

        if ($user->hasPermission('exam_attempts.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('user_id', $examAttempt->user_id)->exists();
    }

    /**
     * Listing every attempt for an exam — management/oversight only,
     * never a student's own-attempts view (which goes through view()
     * on a single already-known attempt instead).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_attempts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exam_attempts.create');
    }
}
