<?php

use App\Support\Database\TenantIntegrity;
use Illuminate\Database\Migrations\Migration;

/**
 * Task 16A: defense in depth for multi-tenancy. The application always scopes by organization, but a
 * bug or a hand-written query must not be able to link one business's record to another's. A trigger
 * checks, on insert and on change of the link, that the record and the record it points to have the
 * same organization_id. (Plain foreign keys only prove the parent exists, not whose it is.)
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantIntegrity::install();
    }

    public function down(): void
    {
        TenantIntegrity::remove();
    }
};
