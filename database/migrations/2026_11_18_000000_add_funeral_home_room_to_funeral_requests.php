<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funeral_requests', function (Blueprint $table) {
            $table->string('funeral_home_room')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('funeral_requests', function (Blueprint $table) {
            $table->dropColumn('funeral_home_room');
        });
    }
};
