<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('from_stage_id')->nullable(); // null = initial placement
            $table->unsignedBigInteger('to_stage_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'changed_at']);
            $table->index(['tenant_id', 'to_stage_id', 'changed_at']);

            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'from_stage_id'])->references(['tenant_id', 'id'])->on('pipeline_stages');
            $table->foreign(['tenant_id', 'to_stage_id'])->references(['tenant_id', 'id'])->on('pipeline_stages');
            $table->foreign(['tenant_id', 'changed_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id'); // actor
            $table->string('type', 16); // call | meeting | note | email | other
            $table->string('subject', 200)->nullable();
            $table->text('body')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'user_id']);

            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        Schema::create('follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('lead_id');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 8)->default('medium');
            $table->dateTime('due_at');
            $table->unsignedBigInteger('assigned_to');
            $table->unsignedBigInteger('created_by');
            $table->string('status', 12)->default('open'); // open | completed
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'assigned_to', 'status', 'due_at'], 'tasks_tenant_assignee_status_due_index');
            $table->index(['tenant_id', 'lead_id', 'status']);

            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'assigned_to'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'completed_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        // Stage history is evidence for reporting: append-only at the database level (see 000500 for the audit log).
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER lead_stage_history_no_update BEFORE UPDATE ON lead_stage_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lead_stage_history is append-only'");
            DB::unprepared("CREATE TRIGGER lead_stage_history_no_delete BEFORE DELETE ON lead_stage_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lead_stage_history is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS lead_stage_history_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS lead_stage_history_no_delete');
        }
        Schema::dropIfExists('follow_up_tasks');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_stage_history');
    }
};
