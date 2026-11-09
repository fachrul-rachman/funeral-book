<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funeral_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_code')->unique();
            $table->enum('status', ['new_request', 'contacted', 'in_progress', 'completed', 'cancelled']);
            foreach (['applicant_name', 'relationship_to_deceased', 'deceased_name', 'funeral_home_location'] as $field) {
                $table->string($field);
            }
            $table->string('applicant_whatsapp_e164', 16);
            $table->date('deceased_date_of_death');
            $table->boolean('has_plot');
            $table->enum('burial_site', ['karawang', 'tangerang'])->nullable();
            $table->string('lot_number')->nullable();
            $table->boolean('paid_in_full')->nullable();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users');
            $table->foreignUuid('first_contacted_by')->nullable()->constrained('users');
            foreach (['first_contacted_at', 'schedule_confirmed_at', 'completed_at', 'cancelled_at'] as $field) {
                $table->timestampTz($field)->nullable();
            }
            $table->text('cancellation_reason')->nullable();
            $table->string('consent_text_version');
            $table->text('consent_text_snapshot');
            $table->timestampTz('consented_at');
            $table->timestampTz('applicant_estimated_burial_at');
            $table->timestampTz('current_burial_at')->index();
            $table->timestampTz('submitted_at')->index();
            $table->timestampsTz();
            $table->index(['status', 'submitted_at']);
            $table->index(['responsible_user_id', 'status']);
        });

        Schema::create('request_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('funeral_request_id')->constrained();
            $table->string('type');
            $table->string('storage_disk');
            $table->string('object_key')->unique();
            $table->string('original_file_name');
            $table->string('declared_mime');
            $table->string('detected_mime');
            $table->unsignedBigInteger('byte_size');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('uploaded_at');
            $table->enum('validation_state', ['pending', 'accepted', 'rejected']);
            $table->timestampTz('superseded_at')->nullable();
            $table->index(['funeral_request_id', 'type']);
        });

        DB::statement("CREATE UNIQUE INDEX request_documents_current_unique ON request_documents (funeral_request_id, type) WHERE validation_state = 'accepted' AND superseded_at IS NULL AND type <> 'contact_evidence'");

        Schema::create('request_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('funeral_request_id')->constrained();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->string('action');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['funeral_request_id', 'occurred_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('funeral_request_id')->constrained();
            $table->enum('event_type', ['new_request', 'completed']);
            $table->string('recipient_role')->nullable();
            $table->string('destination_e164', 16);
            $table->string('template_name');
            $table->string('idempotency_key')->unique();
            $table->enum('state', ['pending', 'processing', 'accepted', 'sent', 'delivered', 'read', 'failed', 'unknown']);
            $table->string('provider_message_id')->nullable()->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message_redacted')->nullable();
            $table->timestampsTz();
            $table->index(['state', 'next_attempt_at']);
        });

        Schema::create('public_submission_keys', function (Blueprint $table) {
            $table->char('key_digest', 64)->primary();
            $table->char('payload_fingerprint', 64);
            $table->foreignUuid('funeral_request_id')->constrained();
            $table->timestampTz('expires_at')->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE funeral_requests ADD CONSTRAINT funeral_requests_cancel_reason CHECK (status <> 'cancelled' OR nullif(btrim(cancellation_reason), '') IS NOT NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_submission_keys');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('request_audit_logs');
        Schema::dropIfExists('request_documents');
        Schema::dropIfExists('funeral_requests');
    }
};
