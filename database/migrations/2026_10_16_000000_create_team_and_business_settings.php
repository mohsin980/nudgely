<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 13: owner/manager/staff roles, member status, business profile and preferences,
 * invitations, per-user notification preferences and the organization activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('role');
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampTz('last_active_at')->nullable();
            $table->index(['organization_id', 'status']);
        });

        // admin/member → owner/manager/staff: the first admin of each organization becomes its owner.
        DB::statement("update users set role = 'manager' where role = 'admin'");
        DB::statement("update users set role = 'staff' where role = 'member'");
        DB::statement("update users u set role = 'owner' where u.id in (select min(id) from users where role = 'manager' and organization_id is not null group by organization_id)");
        DB::statement("alter table users alter column role set default 'staff'");

        // Exactly one owner per organization (also the last-owner guard at the database level).
        DB::statement("create unique index users_one_owner_per_organization on users (organization_id) where role = 'owner'");

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('legal_name', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('US');
            $table->string('logo_path', 255)->nullable()->unique();
            $table->char('currency', 3)->default('USD');
            $table->string('date_format', 16)->default('M j, Y');
            $table->string('time_format', 4)->default('12h');
            // Typed defaults (estimates, follow-ups, tasks, automations, business hours, notifications): see OrganizationSettings.
            $table->jsonb('settings')->nullable();
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('name', 100);
            $table->string('role', 16);
            // sha256 of the token in the link; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        // One open invitation per email and organization.
        DB::statement('create unique index team_invitations_one_open_per_email on team_invitations (organization_id, email) where accepted_at is null and revoked_at is null');

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'organization_id', 'type', 'channel']);
        });

        Schema::create('organization_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_activity');
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('team_invitations');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['logo_path']);
            $table->dropColumn(['legal_name', 'email', 'phone', 'website', 'address_line1', 'address_line2', 'city', 'state', 'postal_code',
                'country', 'logo_path', 'currency', 'date_format', 'time_format', 'settings']);
        });

        DB::statement('drop index if exists users_one_owner_per_organization');
        DB::statement("update users set role = 'admin' where role in ('owner', 'manager')");
        DB::statement("update users set role = 'member' where role = 'staff'");
        DB::statement("alter table users alter column role set default 'member'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['status', 'suspended_at', 'removed_at', 'last_active_at']);
        });
    }
};
