<?php

namespace App\Policies;

use App\Models\QuestionPaper;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class QuestionPaperPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('question_papers.view');
    }

    /**
     * No per-row ownership check here — TenantScope already narrows
     * which rows a teacher or school_admin can even reach to their own
     * school, and within a school every teacher may view/print any
     * colleague's paper, not only their own.
     */
    public function view(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user->hasPermission('question_papers.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user instanceof TeacherUser
            && $user->school_id !== null
            && $user->hasPermission('question_papers.create');
    }

    /**
     * Unlike view(), update/delete ARE restricted to the paper's own
     * creator — a colleague can view/print but never edit, per the
     * feature's explicit requirement.
     */
    public function update(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user instanceof TeacherUser
            && $questionPaper->teacher_id === $user->id
            && $user->hasPermission('question_papers.update');
    }

    public function delete(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user instanceof TeacherUser
            && $questionPaper->teacher_id === $user->id
            && $user->hasPermission('question_papers.delete');
    }
}
