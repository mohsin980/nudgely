<?php

namespace App\Console\Commands;

use App\Enums\Team\MemberStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to become a platform administrator. It runs on the server, for an account that already exists:
 * there is no web form, registration option or API for it.
 */
class PlatformAdminGrantCommand extends Command
{
    protected $signature = 'platform-admin:grant {email : Email of an existing, active account}';

    protected $description = 'Allow an existing user to sign in to the Super Admin panel (/admin)';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account has that email. Register the account first, then run this command.');

            return self::FAILURE;
        }

        if ($user->status !== MemberStatus::Active) {
            $this->error('That account is not active, so it cannot be made a platform administrator.');

            return self::FAILURE;
        }

        if ($user->isPlatformAdmin()) {
            $this->line("Already a platform administrator: {$user->email}.");

            return self::SUCCESS;
        }

        // forceCreate: the model refuses mass assignment, so a grant can only come from deliberate code like this.
        PlatformAdmin::query()->forceCreate(['user_id' => $user->id]);

        $this->info("Granted: {$user->email} can now sign in at /admin.");

        return self::SUCCESS;
    }
}
