<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('automation_action_runs')) {
            return;
        }

        Schema::connection($this->connection)->create('automation_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('execution_id')->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->uuid('causation_id')->nullable();
            $table->string('action_key', 191)->index();
            $table->string('origin', 191)->nullable()->index();
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('idempotency_key', 191)->nullable()->index();
            $table->json('input_json')->nullable();
            $table->json('output_json')->nullable();
            $table->json('warning_json')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('automation_action_runs');
    }
};
