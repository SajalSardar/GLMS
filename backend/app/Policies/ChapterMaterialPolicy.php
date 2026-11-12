<?php

namespace App\Policies;

use App\Models\ChapterMaterial;
use Illuminate\Contracts\Auth\Authenticatable;

class ChapterMaterialPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    public function view(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.view');
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
