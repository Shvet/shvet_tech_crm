<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Nullable only for platform-level events (e.g. failed login, provisioning before context).
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            // RESTRICT (not SET NULL): users are deactivated, never deleted, and audit rows must not be rewritten by FK actions.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type', 64)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('name', 100);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 100);
            $table->unsignedSmallInteger('position');
            $table->string('outcome', 8)->default('open'); // open | won | lost
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'position']);
        });

        // Minimal lead table: the tenant-owned aggregate used to prove scoping and
        // composite-FK isolation in Phase 1. Lead CRUD and remaining columns follow
        // in later (additive) migrations.
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('public_id', 26)->unique();
            $table->string('full_name');
            $table->string('phone_e164', 20)->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('lead_source_id');
            $table->unsignedBigInteger('pipeline_stage_id');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'phone_e164']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'pipeline_stage_id']);
            $table->index(['tenant_id', 'owner_id']);

            // Composite FKs: the DB itself rejects references into another tenant.
            $table->foreign(['tenant_id', 'lead_source_id'])->references(['tenant_id', 'id'])->on('lead_sources');
            $table->foreign(['tenant_id', 'pipeline_stage_id'])->references(['tenant_id', 'id'])->on('pipeline_stages');
            // Owner must be a member of the same tenant.
            $table->foreign(['tenant_id', 'owner_id'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
    }
};
