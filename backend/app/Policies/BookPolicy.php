<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('books.view');
    }

    public function view(User $user, Book $book): bool
    {
        return $user->hasPermission('books.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('books.create');
    }

    public function update(User $user, Book $book): bool
    {
        return $user->hasPermission('books.update');
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->hasPermission('books.delete');
    }
}
