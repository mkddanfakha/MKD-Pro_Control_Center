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
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('monthly_unit_amount')->nullable()->after('paid_at');
            $table->unsignedSmallInteger('credit_months_purchased')->nullable()->after('monthly_unit_amount');
            $table->timestamp('credit_exhausted_at')->nullable()->after('credit_months_purchased');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_unit_amount',
                'credit_months_purchased',
                'credit_exhausted_at',
            ]);
        });
    }
};
