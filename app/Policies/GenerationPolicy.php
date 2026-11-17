<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GenerationPolicy
{
    /**
     * Admins can view any generation. Other users get 404 rather than 403,
     * so they cannot even confirm a generation exists.
     */
    public function view(User $user, Generation $generation): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        return $user->id === $generation->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
