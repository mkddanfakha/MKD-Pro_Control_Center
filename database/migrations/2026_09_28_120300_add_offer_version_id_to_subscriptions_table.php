<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('offer_version_id')
                ->nullable()
                ->after('installation_id')
                ->constrained('offer_versions')
                ->restrictOnDelete();

            $table->index('offer_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['offer_version_id']);
            $table->dropColumn('offer_version_id');
        });
    }
};
