<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes audit_logs append-only at the database level: UPDATE and DELETE statements are rejected for
 * every client, including application code, the query builder and ad-hoc SQL by the app DB user.
 *
 * Notes: FK cascades (tenant purge) do not fire MySQL triggers, which is what allows a deliberate
 * tenant erasure; retention pruning will need a dedicated, audited path (e.g. partition drop by an
 * operator account). Creating triggers needs the TRIGGER privilege (and, with binary logging on,
 * SUPER or log_bin_trust_function_creators=1) for the account that runs migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
