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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->engine('InnoDB');

            $table->id();
            $table->foreignId('installation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XOF');
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('installation_id');
            $table->index('status');
            $table->index('current_period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
