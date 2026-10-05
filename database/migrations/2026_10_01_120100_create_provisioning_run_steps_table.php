<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étapes d'un run de provisioning (ADR-335).
 *
 * Statuts step (application) : pending, running, succeeded, failed, manual_intervention_required, skipped.
 * Pas de statut cancelled au niveau step — annulation gérée au niveau run.
 * Transitions applicatives : non contraintes en SQL (voir spec provisioning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_run_id')->constrained('provisioning_runs')->restrictOnDelete();
            $table->string('step_key', 64);
            $table->unsignedSmallInteger('step_order');
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provisioning_run_id', 'step_key'], 'uq_prs_run_step_key');
            $table->index(['provisioning_run_id', 'step_order'], 'idx_prs_run_order');
            $table->index(['provisioning_run_id', 'status'], 'idx_prs_run_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_run_steps');
    }
};
