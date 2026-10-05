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
        Schema::create('subscription_offer_snapshots', function (Blueprint $table) {
            $table->engine('InnoDB');

            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained('subscriptions')->restrictOnDelete();
            $table->foreignId('offer_version_id')->constrained('offer_versions')->restrictOnDelete();
            $table->string('offer_code');
            $table->string('offer_version_code');
            $table->string('product_code');
            $table->string('offer_name');
            $table->unsignedInteger('catalogue_price');
            $table->unsignedInteger('effective_price_at_subscription');
            $table->string('currency', 3);
            $table->string('billing_cycle');
            $table->json('inclusions');
            $table->json('limitations');
            $table->json('exclusions');
            $table->text('commercial_conditions')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('document_hash')->nullable();
            $table->string('contract_reference')->nullable();
            $table->text('negotiated_rate_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_offer_snapshots');
    }
};
