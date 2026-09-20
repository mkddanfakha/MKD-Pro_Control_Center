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
        Schema::create('installation_modules', function (Blueprint $table) {
            $table->engine('InnoDB');

            $table->id();
            $table->foreignId('installation_id')->constrained()->restrictOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active');
            $table->string('version')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['installation_id', 'module_id']);

            $table->index('installation_id');
            $table->index('module_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installation_modules');
    }
};
