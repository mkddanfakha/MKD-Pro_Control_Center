<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'subscription_reminders_logical_unique';

    public function up(): void
    {
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('reminder_type', 64);
            $table->unsignedSmallInteger('threshold_days');
            $table->dateTime('scheduled_for');
            $table->dateTime('detected_at');
            $table->dateTime('sent_at')->nullable();
            $table->string('status', 32)->default('detected');
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'reminder_type', 'threshold_days', 'scheduled_for'],
                self::UNIQUE_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
