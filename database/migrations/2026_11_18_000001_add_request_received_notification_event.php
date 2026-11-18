<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->events(['new_request', 'completed', 'request_received']);
    }

    public function down(): void
    {
        if (DB::table('notification_deliveries')->where('event_type', 'request_received')->exists()) {
            throw new RuntimeException('Cannot remove the receipt event while customer receipt records exist.');
        }
        $this->events(['new_request', 'completed']);
    }

    /** @param list<string> $events */
    private function events(array $events): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL stores Laravel enum values in a CHECK constraint.
            DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_event_type_check');
            $values = "'".implode("', '", $events)."'";
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_event_type_check CHECK (event_type IN ($values))");
        } else {
            Schema::table('notification_deliveries', function (Blueprint $table) use ($events) {
                $table->enum('event_type', $events)->change();
            });
        }
    }
};
