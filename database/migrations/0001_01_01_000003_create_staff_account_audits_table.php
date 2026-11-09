<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_account_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->foreignUuid('user_id')->constrained();
            $table->string('previous_role')->nullable();
            $table->boolean('previous_active')->nullable();
            $table->string('role');
            $table->boolean('active');
            $table->boolean('password_changed');
            $table->timestampTz('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_account_audits');
    }
};
