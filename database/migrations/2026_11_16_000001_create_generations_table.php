<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // ULID, sent to Pixelforge as metadata.generation_id, so a webhook
            // can find its row even before POST /v1/generations has returned.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // pending = not yet accepted by Pixelforge; then Pixelforge's own
            // queued → processing → succeeded | failed.
            $table->string('status')->index();
            $table->string('provider_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // All money is integer nanodollars ($1 = 1_000_000_000).
            // What the user saw before submitting: the price range.
            $table->unsignedBigInteger('estimated_min_nanos');
            $table->unsignedBigInteger('estimated_max_nanos');

            // Filled in when the generation succeeds.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charged_nanos')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            // Pixelforge bills a generation on the UTC day it completed.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Every movement of a user's credits. The balance is the sum of amounts.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            // purchase (+), hold (−max estimate), release (+hold back), charge (−actual)
            $table->string('type');
            $table->bigInteger('amount_nanos');
            $table->timestamps();

            // A generation can be held, released and charged at most once each,
            // whatever happens to webhooks, polling and retries.
            $table->unique(['generation_id', 'type']);
            $table->index('user_id');
        });

        // Event ids already handled, so a redelivered webhook is a no-op.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('generations');
    }
};
