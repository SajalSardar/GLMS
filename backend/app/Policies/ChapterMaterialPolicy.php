<?php

namespace App\Policies;

use App\Models\ChapterMaterial;
use App\Models\StudentUser;
use Illuminate\Contracts\Auth\Authenticatable;

class ChapterMaterialPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    /**
     * Gates show() AND the downloadLink() action — a student must also
     * belong to this material's own section when one is set, matching
     * index()'s own filter. Without this, a student could bypass that
     * listing filter by calling downloadLink() directly with a material
     * id from a different section (e.g. guessed or shared by a
     * classmate). A non-student actor (teacher/admin) with the flat
     * permission is unrestricted, same as before this scoping existed.
     */
    public function view(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        if (! $user->hasPermission('chapter_materials.view')) {
            return false;
        }

        if ($chapterMaterial->section_id === null || ! $user instanceof StudentUser) {
            return true;
        }

        return $user->profile?->section_id === $chapterMaterial->section_id;
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('chapter_materials.create');
    }

    public function update(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.update');
    }

    public function delete(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.delete');
    }
}
