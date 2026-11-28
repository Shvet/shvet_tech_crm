<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily follow-up reminder e-mails (additive). The preference is per workspace membership; the digest table makes
 * sending idempotent: at most one digest per member per local day, even if the scheduler runs twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->boolean('task_reminders')->default(true)->after('status');
        });

        Schema::create('task_reminder_digests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->date('local_date');
            $table->unsignedSmallInteger('task_count');
            $table->dateTime('sent_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminder_digests');
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->dropColumn('task_reminders');
        });
    }
};
