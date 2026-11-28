<?php

namespace App\Console\Commands;

use App\Actions\Tasks\SendTaskReminderDigests;
use App\Support\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:send-task-reminders')]
#[Description('E-mail each member a daily summary of overdue and due-today follow-ups (once per local day, from crm.reminder_hour).')]
class SendTaskReminders extends Command
{
    public function handle(SendTaskReminderDigests $send): int
    {
        $total = 0;
        Tenancy::eachActiveTenant(function () use ($send, &$total): void {
            $total += $send();
        });

        $this->info("Sent {$total} reminder digest(s).");

        return self::SUCCESS;
    }
}
