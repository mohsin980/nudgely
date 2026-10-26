<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imported estimates record where they came from. Their import status is kept apart from follow-up eligibility:
 * "imported_status" is what the file said and is never proof that anything was sent.
 *
 * Customers may now have no email address (they are imported, but cannot receive email follow-ups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unsignedBigInteger('import_id')->nullable()->after('revision_of_id');
            $table->string('external_reference', 120)->nullable()->after('import_id');
            $table->string('imported_status', 20)->nullable()->after('external_reference');

            $table->foreign(['import_id', 'organization_id'], 'estimates_import_fk')
                ->references(['id', 'organization_id'])->on('estimate_imports');
            $table->unique(['organization_id', 'external_reference'], 'estimates_org_external_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 254)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign('estimates_import_fk');
            $table->dropUnique('estimates_org_external_unique');
            $table->dropColumn(['import_id', 'external_reference', 'imported_status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 254)->nullable(false)->change();
        });
    }
};
