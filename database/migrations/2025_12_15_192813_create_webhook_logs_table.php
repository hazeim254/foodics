<?php

use App\Enums\WebhookStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->dateTime('timestamp');
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->enum('status', WebhookStatus::values())->default(WebhookStatus::Pending);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Extracted fields for querying
            $table->unsignedBigInteger('business_reference')->nullable();
            $table->string('order_id')->nullable();
            $table->unsignedInteger('order_reference')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('event');
            $table->index('status');
            $table->index('timestamp');
            $table->index('business_reference');
            $table->index('order_id');
            $table->index(['event', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
