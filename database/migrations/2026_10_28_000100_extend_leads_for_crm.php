<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2: everything the lead workflow needs on top of the Phase 1a skeleton (additive only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('email');
            $table->string('priority', 8)->default('medium')->after('company_name');
            $table->decimal('estimated_value', 14, 2)->nullable()->after('priority');
            $table->unsignedBigInteger('created_by')->nullable()->after('owner_id');
            // Client-generated per form instance; makes double submits idempotent (see CreateLead).
            $table->char('submission_token', 36)->nullable()->after('created_by');
            $table->dateTime('stage_changed_at')->nullable()->after('submission_token');
            $table->dateTime('last_contacted_at')->nullable()->after('stage_changed_at');
            $table->dateTime('archived_at')->nullable()->after('last_contacted_at');
            $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at');

            // Scoped per creator: one user's token can never collide with (or reveal) another user's.
            $table->unique(['tenant_id', 'created_by', 'submission_token'], 'leads_tenant_creator_token_unique');
            $table->index(['tenant_id', 'archived_at', 'pipeline_stage_id'], 'leads_tenant_archived_stage_index');
            $table->index(['tenant_id', 'owner_id', 'archived_at'], 'leads_tenant_owner_archived_index');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'full_name']);

            // The actor columns must reference members of the SAME tenant.
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'archived_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'created_by']);
            $table->dropForeign(['tenant_id', 'archived_by']);
            $table->dropUnique('leads_tenant_creator_token_unique');
            $table->dropIndex('leads_tenant_archived_stage_index');
            $table->dropIndex('leads_tenant_owner_archived_index');
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'full_name']);
            $table->dropColumn(['company_name', 'priority', 'estimated_value', 'created_by', 'submission_token', 'stage_changed_at', 'last_contacted_at', 'archived_at', 'archived_by']);
        });
    }
};
