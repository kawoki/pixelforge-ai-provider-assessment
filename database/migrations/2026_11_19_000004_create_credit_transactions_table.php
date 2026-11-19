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
        // Append-only ledger (ADR-0008, ADR-0011).
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 24, 12);
            $table->decimal('balance_after', 24, 12);
            // Effect-level idempotency: "charge:{generation_id}", "purchase:{uuid}".
            $table->string('idempotency_key')->unique();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        $this->addInvariants();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }

    private function addInvariants(): void
    {
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_type_check
            CHECK (type IN ('purchase', 'charge', 'adjustment'))");

        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_charge_shape
            CHECK (type <> 'charge' OR (amount <= 0 AND generation_id IS NOT NULL))");

        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_purchase_positive
            CHECK (type <> 'purchase' OR amount > 0)");

        // At most one charge per generation, even if idempotency keys were ever built differently.
        DB::statement("CREATE UNIQUE INDEX credit_transactions_one_charge_per_generation
            ON credit_transactions (generation_id) WHERE type = 'charge'");
    }
};
