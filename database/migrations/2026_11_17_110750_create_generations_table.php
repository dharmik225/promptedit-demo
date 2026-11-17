<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_generation_id')->nullable();
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->jsonb('reference_image_urls')->default('[]');
            $table->string('status', 16);

            $table->decimal('markup_percentage', 8, 4);
            $table->jsonb('rates');

            $table->unsignedInteger('estimated_input_text_tokens');
            $table->unsignedInteger('estimated_input_image_tokens');
            $table->unsignedInteger('estimated_output_tokens');
            $table->unsignedInteger('estimated_reasoning_tokens');

            $table->unsignedInteger('actual_input_text_tokens')->nullable();
            $table->unsignedInteger('actual_input_image_tokens')->nullable();
            $table->unsignedInteger('actual_output_tokens')->nullable();
            $table->unsignedInteger('actual_reasoning_tokens')->nullable();
            $table->unsignedInteger('actual_total_tokens')->nullable();

            $table->decimal('estimated_provider_cost', 24, 12);
            $table->decimal('estimated_user_cost', 24, 12);
            $table->decimal('actual_provider_cost', 24, 12)->nullable();
            $table->decimal('actual_user_cost', 24, 12)->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('provider_metadata')->nullable();

            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_generation_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
