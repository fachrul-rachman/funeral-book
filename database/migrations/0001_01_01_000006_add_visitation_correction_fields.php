<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitation_plan_requests', function (Blueprint $table) {
            $table->uuid('generation')->nullable();
            $table->jsonb('validation_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitation_plan_requests', fn (Blueprint $table) => $table->dropColumn(['generation', 'validation_fields']));
    }
};
