<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every admin alert opened, reminded or resolved, and whether its email went out (ADR-0015).
        // Kept out of laravel.log; read on the System status page. Written with the query builder only.
        Schema::create('admin_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('event', 16); // opened | reminder | resolved
            $table->string('title');
            $table->text('details');
            $table->jsonb('context')->nullable();
            $table->string('email_status', 16); // sent | failed | no_recipients
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->text('email_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alert_logs');
    }
};
