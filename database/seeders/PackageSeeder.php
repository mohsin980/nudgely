<?php

namespace Database\Seeders;

use App\Models\PlanSetting;
use Illuminate\Database\Seeder;

/**
 * Plans and add-ons, written to the database (plan_settings). The values are defined here, not read from config.
 *
 * Each run updates the package with the same key, so there are never copies: a changed value here is updated in the
 * database. Stripe price IDs and their legacy history are not part of this seed, so a run never clears the Stripe
 * prices an administrator has set.
 *
 * Run with: php artisan db:seed --class=PackageSeeder --force
 */
class PackageSeeder extends Seeder
{
    /** Amounts are minor units: USD cents, PKR paisa. */
    private const PLANS = [
        'free' => [
            'name' => 'Free', 'description' => null,
            'prices' => ['USD' => 0, 'PKR' => 0],
            'limits' => ['customers' => 100, 'automations' => 3, 'team_members' => 1, 'outbound_emails' => 500, 'estimates' => 50, 'ai_conversations' => 0],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification'],
        ],
        'starter' => [
            'name' => 'Starter', 'description' => null,
            'prices' => ['USD' => 2900, 'PKR' => 800000],
            'limits' => ['customers' => 500, 'automations' => 15, 'team_members' => 3, 'outbound_emails' => 2500, 'estimates' => 250, 'ai_conversations' => 100],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'ai_assistant', 'team_roles', 'custom_branding'],
        ],
        'pro' => [
            'name' => 'Pro', 'description' => null,
            'prices' => ['USD' => 7900, 'PKR' => 2200000],
            'limits' => ['customers' => 2000, 'automations' => 50, 'team_members' => 10, 'outbound_emails' => 10000, 'estimates' => 1000, 'ai_conversations' => 300],
            'features' => ['estimates', 'follow_ups', 'automations', 'ai_reply_classification', 'ai_assistant', 'team_roles', 'custom_branding', 'priority_support'],
        ],
    ];

    /** Add-ons: limits are the extra amounts added to the plan they are bought with. */
    private const ADDONS = [
        'ai_assistant' => [
            'name' => 'AI WhatsApp assistant',
            'description' => 'Your AI assistant answers WhatsApp leads, qualifies them and hands over to your team. Includes 100 AI conversations a month.',
            'prices' => ['USD' => 1900, 'PKR' => 500000],
            'applies_to' => ['free'],
            'features' => ['ai_assistant'],
            'limits' => ['ai_conversations' => 100],
        ],
        'ai_conversations' => [
            'name' => '+200 AI conversations a month',
            'description' => 'More AI assistant conversations each month, for busy businesses.',
            'prices' => ['USD' => 1200, 'PKR' => 350000],
            'applies_to' => ['starter', 'pro'],
            'features' => [],
            'limits' => ['ai_conversations' => 200],
        ],
        'customers' => [
            'name' => '+1,000 customers',
            'description' => 'Room for 1,000 more customers.',
            'prices' => ['USD' => 1000, 'PKR' => 280000],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['customers' => 1000],
        ],
        'team_members' => [
            'name' => '+5 team members',
            'description' => 'Five more people on your team, each with their own role.',
            'prices' => ['USD' => 1000, 'PKR' => 280000],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['team_members' => 5],
        ],
        'outbound_emails' => [
            'name' => '+10,000 emails a month',
            'description' => 'Ten thousand more outbound emails each month.',
            'prices' => ['USD' => 800, 'PKR' => 220000],
            'applies_to' => ['free', 'starter', 'pro'],
            'features' => [],
            'limits' => ['outbound_emails' => 10000],
        ],
        'custom_branding' => [
            'name' => 'Custom branding',
            'description' => 'Your logo and colours on emails and estimates. Already included in Starter and Pro.',
            'prices' => ['USD' => 900, 'PKR' => 250000],
            'applies_to' => ['free'],
            'features' => ['custom_branding'],
            'limits' => [],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $key => $plan) {
            $this->save($key, PlanSetting::KIND_PLAN, $plan);
        }

        foreach (self::ADDONS as $key => $addon) {
            $this->save($key, PlanSetting::KIND_ADDON, $addon);
        }
    }

    /**
     * Create the package, or update the existing row with this key. Never creates a copy.
     *
     * @param  array<string, mixed>  $package
     */
    private function save(string $key, string $kind, array $package): void
    {
        PlanSetting::query()->firstOrNew(['plan_key' => $key])->forceFill([
            'kind' => $kind,
            'name' => $package['name'],
            'description' => $package['description'] ?? null,
            'prices' => $package['prices'],
            'features' => $package['features'],
            'limits' => $package['limits'],
            'applies_to' => $package['applies_to'] ?? null,
            'active' => true,
        ])->save();
    }
}
