<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funeral_requests', function (Blueprint $table) {
            $table->string('zone')->nullable();
        });
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->jsonb('template_parameters')->nullable();
        });
        Schema::create('visitation_plan_requests', function (Blueprint $table) {
            $table->foreignUuid('funeral_request_id')->primary()->constrained();
            $table->jsonb('payload');
            $table->enum('state', ['pending', 'processing', 'ready', 'failed']);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('url')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestampsTz();
            $table->index(['state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitation_plan_requests');
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn('template_parameters'));
        Schema::table('funeral_requests', fn (Blueprint $table) => $table->dropColumn('zone'));
    }
};
