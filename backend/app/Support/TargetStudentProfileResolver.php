<?php

namespace App\Support;

use App\Models\GuardianUser;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Resolves "which student profile is this *mine*-style request actually
 * about" for either a StudentUser (always their own profile, implicitly)
 * or a GuardianUser (an explicit ?student_profile_id=, checked against
 * the children actually linked to them) — the one piece of logic shared
 * by every student self-service endpoint (attendance, results, class
 * routine, leave history, chapter materials, practice-exam history) now
 * that a guardian may call them too.
 *
 * Mirrors Guardian::isVisibleTo()'s own students()->where(...)->exists()
 * shape rather than inventing a new authorization idiom.
 */
class TargetStudentProfileResolver
{
    public function resolve(Request $request): ?StudentProfile
    {
        $actor = $request->user();

        if ($actor instanceof StudentUser) {
            return $actor->profile;
        }

        abort_unless($actor instanceof GuardianUser, 403);

        $studentProfileId = $request->integer('student_profile_id') ?: null;

        if ($studentProfileId === null) {
            throw ValidationException::withMessages([
                'student_profile_id' => ['Select which child to view.'],
            ]);
        }

        $guardian = $actor->profile;
        abort_if($guardian === null, 403);

        $profile = $guardian->students()->where('student_profiles.id', $studentProfileId)->first();
        abort_if($profile === null, 403);

        return $profile;
    }
}
