<?php

namespace App\Policies;

use App\Models\GalleryWork;
use App\Models\User;

class GalleryWorkPolicy
{
    public function view(?User $user, GalleryWork $work): bool
    {
        if ($work->status === GalleryWork::STATUS_APPROVED) {
            return true;
        }

        return $user !== null && (int) $work->user_id === (int) $user->id;
    }

    public function update(User $user, GalleryWork $work): bool
    {
        return (int) $work->user_id === (int) $user->id;
    }

    public function delete(User $user, GalleryWork $work): bool
    {
        return (int) $work->user_id === (int) $user->id;
    }

    public function like(User $user, GalleryWork $work): bool
    {
        return $work->status === GalleryWork::STATUS_APPROVED;
    }
}
