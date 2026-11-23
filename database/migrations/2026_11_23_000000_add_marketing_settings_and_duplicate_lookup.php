<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('marketing_whatsapp_e164', 16)->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::table('notification_settings')->insert(['id' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
        Schema::table('funeral_requests', function (Blueprint $table) {
            $table->index(['applicant_whatsapp_e164', 'deceased_date_of_death'], 'funeral_requests_duplicate_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('funeral_requests', function (Blueprint $table) {
            $table->dropIndex('funeral_requests_duplicate_lookup');
        });
        Schema::dropIfExists('notification_settings');
    }
};
