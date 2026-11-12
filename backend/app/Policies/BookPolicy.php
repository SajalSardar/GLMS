<?php

namespace App\Policies;

use App\Models\Book;
use Illuminate\Contracts\Auth\Authenticatable;

class BookPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('books.view');
    }

    public function view(Authenticatable $user, Book $book): bool
    {
        return $user->hasPermission('books.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('books.create');
    }

    public function update(Authenticatable $user, Book $book): bool
    {
        return $user->hasPermission('books.update');
    }

    public function delete(Authenticatable $user, Book $book): bool
    {
        return $user->hasPermission('books.delete');
    }
}
