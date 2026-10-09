<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Console\Command;

/**
 * For development and testing only. Onboarding can't be reset from the application, so users can
 * never lose their setup by accident; this clears the progress markers (no business data is deleted).
 */
class ResetOnboardingCommand extends Command
{
    protected $signature = 'onboarding:reset {organization : Organization ID} {--force : Required in production}';

    protected $description = 'Restart onboarding for one organization (development/testing; deletes no business data)';

    public function handle(OnboardingService $onboarding): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to reset onboarding in production without --force.');

            return self::FAILURE;
        }

        $organization = Organization::query()->find($this->argument('organization'));

        if ($organization === null) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $onboarding->reset($organization);
        $this->info("Onboarding restarted for {$organization->name}.");

        return self::SUCCESS;
    }
}
