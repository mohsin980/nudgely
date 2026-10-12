<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;

class PlatformAdminRevokeCommand extends Command
{
    protected $signature = 'platform-admin:revoke {email : Email of the platform administrator}';

    protected $description = 'Remove platform administrator access and platform roles from a user (the account itself is kept)';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null || ! $user->isPlatformAdmin()) {
            $this->error('That account is not a platform administrator.');

            return self::FAILURE;
        }

        $user->syncRoles([]);
        PlatformAdmin::query()->where('user_id', $user->id)->delete();

        $this->info("Revoked: {$user->email} can no longer sign in to /admin.");

        return self::SUCCESS;
    }
}
