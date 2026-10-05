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
        Schema::create('offer_versions', function (Blueprint $table) {
            $table->engine('InnoDB');

            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->string('version');
            $table->string('code')->unique();
            $table->unsignedInteger('price');
            $table->string('currency', 3);
            $table->string('billing_cycle');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status');
            $table->text('description');
            $table->json('inclusions');
            $table->json('limitations');
            $table->json('exclusions');
            $table->text('commercial_conditions')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'version']);
            $table->index('offer_id');
            $table->index('code');
            $table->index(['status', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_versions');
    }
};
