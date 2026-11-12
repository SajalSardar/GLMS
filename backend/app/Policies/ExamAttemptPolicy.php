<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\GuardianUser;
use App\Models\StudentUser;
use Illuminate\Contracts\Auth\Authenticatable;

class ExamAttemptPolicy
{
    /**
     * Viewing a single attempt: the attempt's own student, their linked
     * guardian, or a management/oversight role holding exam_attempts.view.
     * No flat permission is required for the first two — same "own
     * record" precedent as ChapterVideoController::updateProgress().
     * exam_attempts.user_id is student-only (see migration
     * 2026_11_12_100200), so the identity check must also confirm $user
     * is a StudentUser — otherwise a same-numeric-id row in a different
     * user table would wrongly match.
     */
    public function view(Authenticatable $user, ExamAttempt $examAttempt): bool
    {
        if ($user instanceof StudentUser && $user->getAuthIdentifier() === $examAttempt->user_id) {
            return true;
        }

        if ($user->hasPermission('exam_attempts.view')) {
            return true;
        }

        if (! $user instanceof GuardianUser || $user->profile === null) {
            return false;
        }

        return $user->profile->students()->where('user_id', $examAttempt->user_id)->exists();
    }

    /**
     * Listing every attempt for an exam — management/oversight only,
     * never a student's own-attempts view (which goes through view()
     * on a single already-known attempt instead).
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('exam_attempts.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('exam_attempts.create');
    }
}
