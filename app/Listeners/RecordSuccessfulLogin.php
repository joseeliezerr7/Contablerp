<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Rastro mínimo de acceso. La auditoría completa vive en la tabla audit_logs.
 */
class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();
    }
}
