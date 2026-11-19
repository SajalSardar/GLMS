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
     * Gates show() AND the downloadLink() action — a student must match
     * this material's own full scope tuple exactly, mirroring
     * ChapterMaterialController::index()'s own filter (including: an
     * unscoped material is NOT visible to a student either — it was
     * never actually verified to belong to their class, so it isn't
     * shown as a "visible to everyone" fallback the way it is for a
     * guest/non-student actor). Without this, a student could bypass
     * that listing filter by calling downloadLink() directly with a
     * material id from a different section (e.g. guessed or shared by a
     * classmate). A non-student actor (teacher/admin) with the flat
     * permission is unrestricted, same as before this scoping existed.
     */
    public function view(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        if (! $user->hasPermission('chapter_materials.view')) {
            return false;
        }

        if (! $user instanceof StudentUser) {
            return true;
        }

        $profile = $user->profile;

        if ($profile === null || $profile->section_id === null) {
            return false;
        }

        return $chapterMaterial->school_id === $profile->school_id
            && $chapterMaterial->district_id === $profile->district_id
            && $chapterMaterial->shift_id === $profile->shift_id
            && $chapterMaterial->section_id === $profile->section_id
            && $chapterMaterial->class_id === $profile->class_id
            && $chapterMaterial->class_version_id === $profile->class_version_id;
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
