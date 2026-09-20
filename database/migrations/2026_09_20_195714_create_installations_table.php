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
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('domain')->nullable();
            $table->string('status')->default('active');
            $table->string('version')->nullable();
            $table->string('database_name')->nullable();
            $table->string('database_host')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
