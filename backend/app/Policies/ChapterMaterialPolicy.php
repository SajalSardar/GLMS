<?php

namespace App\Policies;

use App\Models\ChapterMaterial;
use App\Models\User;

class ChapterMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    public function view(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chapter_materials.create');
    }

    public function update(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.update');
    }

    public function delete(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.delete');
    }
}
