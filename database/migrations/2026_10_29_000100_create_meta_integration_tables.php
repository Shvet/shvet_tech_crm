<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3: Meta Lead Ads integration (additive only). Every cross-table reference is tenant-composite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Stable external identity of imported leads; the unique key is the last line of defence against double imports.
            $table->string('external_provider', 24)->nullable()->after('submission_token');
            $table->string('external_id', 64)->nullable()->after('external_provider');
            $table->unique(['tenant_id', 'external_provider', 'external_id'], 'leads_tenant_external_unique');
        });

        // Integration-created notes have no human author.
        DB::statement('ALTER TABLE lead_activities MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('meta_user_id', 64);
            $table->string('meta_user_name')->nullable();
            // pending_selection | active | reauthorization_required | disconnected
            $table->string('status', 32);
            $table->json('granted_permissions')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('data_access_expires_at')->nullable();
            $table->dateTime('last_validated_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_summary', 255)->nullable();
            // Encrypted blob of the Pages the admin may pick from; cleared once selected or expired.
            $table->longText('pending_assets')->nullable();
            $table->dateTime('pending_assets_expires_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'meta_user_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        Schema::create('meta_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('meta_connection_id');
            $table->char('public_id', 26)->unique();
            // Globally unique: a Page belongs to exactly one tenant, so webhooks can be routed unambiguously.
            $table->string('page_id', 64)->unique();
            $table->string('name');
            $table->text('access_token');
            // pending | subscribed | failed | unsubscribed
            $table->string('subscription_status', 24)->default('pending');
            $table->string('subscription_error', 255)->nullable();
            $table->dateTime('subscribed_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'meta_connection_id'])->references(['tenant_id', 'id'])->on('meta_connections')->cascadeOnDelete();
        });

        Schema::create('meta_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('meta_page_id');
            $table->char('public_id', 26)->unique();
            $table->string('form_id', 64);
            $table->string('form_name')->nullable();
            $table->string('form_status', 24)->nullable();
            $table->unsignedBigInteger('lead_source_id')->nullable();
            $table->unsignedBigInteger('pipeline_stage_id')->nullable();
            $table->unsignedBigInteger('default_owner_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('field_map')->nullable();
            $table->dateTime('last_import_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'meta_page_id', 'form_id'], 'meta_form_mappings_page_form_unique');
            $table->foreign(['tenant_id', 'meta_page_id'])->references(['tenant_id', 'id'])->on('meta_pages')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'lead_source_id'])->references(['tenant_id', 'id'])->on('lead_sources');
            $table->foreign(['tenant_id', 'pipeline_stage_id'])->references(['tenant_id', 'id'])->on('pipeline_stages');
            $table->foreign(['tenant_id', 'default_owner_id'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });
        // An enabled mapping must be able to create leads.
        DB::statement('ALTER TABLE meta_form_mappings ADD CONSTRAINT meta_form_mappings_enabled_complete CHECK (is_enabled = 0 OR (lead_source_id IS NOT NULL AND pipeline_stage_id IS NOT NULL))');

        Schema::create('meta_lead_events', function (Blueprint $table) {
            $table->id();
            // Null while quarantined (page not known to any tenant). Resolved only from trusted meta_pages rows.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('meta_page_id')->nullable();
            $table->string('page_id', 64);
            $table->string('form_id', 64)->nullable();
            $table->string('leadgen_id', 64);
            $table->string('ad_id', 64)->nullable();
            $table->string('adgroup_id', 64)->nullable();
            $table->string('campaign_id', 64)->nullable();
            $table->string('platform', 16)->nullable();
            $table->boolean('is_organic')->nullable();
            $table->dateTime('created_time')->nullable();
            // received | processing | imported | needs_review | dismissed | ignored | failed | quarantined
            $table->string('status', 24);
            $table->string('outcome', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_summary', 255)->nullable();
            $table->json('warnings')->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('matched_lead_id')->nullable();
            // Admin chose "create anyway" for a flagged duplicate; honoured on the next run only.
            $table->boolean('confirm_duplicate')->default(false);
            $table->dateTime('received_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Idempotency: Meta redelivers; one row per (page, leadgen).
            $table->unique(['page_id', 'leadgen_id'], 'meta_lead_events_page_leadgen_unique');
            $table->index(['tenant_id', 'status', 'received_at'], 'meta_lead_events_tenant_status_index');
            $table->index(['status', 'last_attempt_at'], 'meta_lead_events_recovery_index');
            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads')->nullOnDelete();
            $table->foreign(['tenant_id', 'matched_lead_id'])->references(['tenant_id', 'id'])->on('leads')->nullOnDelete();
        });

        Schema::create('meta_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->char('state_hash', 64)->unique();
            $table->char('nonce_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_memberships')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        DB::statement('DELETE FROM lead_activities WHERE user_id IS NULL');
        DB::statement('ALTER TABLE lead_activities MODIFY user_id BIGINT UNSIGNED NOT NULL');
        Schema::dropIfExists('meta_oauth_states');
        Schema::dropIfExists('meta_lead_events');
        Schema::dropIfExists('meta_form_mappings');
        Schema::dropIfExists('meta_pages');
        Schema::dropIfExists('meta_connections');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_tenant_external_unique');
            $table->dropColumn(['external_provider', 'external_id']);
        });
    }
};
