<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Money history must survive: never cascade-delete a user's generations.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('client_reference')->unique();
            $table->string('provider_generation_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size', 32);
            $table->string('quality', 32);
            $table->jsonb('reference_image_urls');

            $table->string('status', 20);
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_expires_at')->nullable();

            // Pricing snapshot (ADR-0004): Pixelforge's rates for this generation + our markup.
            $table->jsonb('rate_card');
            // Up-front price range shown to the user; the max is what a balance reserves (ADR-0011).
            $table->decimal('estimated_user_cost_min', 24, 12);
            $table->decimal('estimated_user_cost_max', 24, 12);
            $table->jsonb('usage')->nullable();
            $table->decimal('actual_provider_cost', 24, 12)->nullable();
            $table->decimal('actual_user_cost', 24, 12)->nullable();

            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_provider_event_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'last_provider_event_at']);
            $table->index(['status', 'completed_at']);
        });

        $this->addInvariants();
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }

    /**
     * Database-level guarantees for money correctness (ADR-0008):
     * only succeeded generations carry an actual cost; failed ones cost nothing.
     */
    private function addInvariants(): void
    {
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_check
            CHECK (status IN ('pending', 'queued', 'processing', 'succeeded', 'failed'))");

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_actual_cost_only_when_succeeded
            CHECK ((status = 'succeeded') = (actual_provider_cost IS NOT NULL AND actual_user_cost IS NOT NULL))");

        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_costs_non_negative
            CHECK (estimated_user_cost_min >= 0 AND estimated_user_cost_max >= estimated_user_cost_min
                AND COALESCE(actual_provider_cost, 0) >= 0 AND COALESCE(actual_user_cost, 0) >= 0)');
    }
};
