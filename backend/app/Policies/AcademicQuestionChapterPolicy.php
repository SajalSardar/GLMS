<?php

namespace App\Policies;

use App\Models\AcademicQuestionChapter;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicQuestionChapterPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_question_chapters.view');
    }

    public function view(Authenticatable $user, AcademicQuestionChapter $academicQuestionChapter): bool
    {
        return $user->hasPermission('academic_question_chapters.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_question_chapters.create');
    }

    public function update(Authenticatable $user, AcademicQuestionChapter $academicQuestionChapter): bool
    {
        return $user->hasPermission('academic_question_chapters.update');
    }

    public function delete(Authenticatable $user, AcademicQuestionChapter $academicQuestionChapter): bool
    {
        return $user->hasPermission('academic_question_chapters.delete');
    }
}
