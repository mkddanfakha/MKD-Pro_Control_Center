<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistance des runs de provisioning (ADR-335).
 *
 * Statuts run (application) : pending, running, succeeded, failed, cancelled, manual_intervention_required.
 * Concurrence : active_installation_key (STORED) + UNIQUE — un seul pending/running par installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained('installations')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('trigger', 32)->default('manual');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_version', 64)->nullable();
            $table->char('target_commit', 40)->nullable();
            $table->string('pipeline_version', 32)->nullable();
            $table->string('current_step', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('retry_of_run_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('active_installation_key')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending', 'running') THEN installation_id ELSE NULL END");
            $table->timestamps();

            $table->unique('active_installation_key', 'uq_pr_one_active_per_installation');
            $table->index(['installation_id', 'created_at'], 'idx_pr_installation_created');
            $table->index(['status', 'created_at'], 'idx_pr_status_created');
            $table->index('retry_of_run_id', 'idx_pr_retry_of');
        });

        Schema::table('provisioning_runs', function (Blueprint $table) {
            $table->foreign('retry_of_run_id', 'fk_pr_retry_of')
                ->references('id')
                ->on('provisioning_runs')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_runs', function (Blueprint $table) {
            $table->dropForeign('fk_pr_retry_of');
        });

        Schema::dropIfExists('provisioning_runs');
    }
};
