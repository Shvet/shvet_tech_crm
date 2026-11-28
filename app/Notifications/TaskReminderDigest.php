<?php

namespace App\Notifications;

use App\Models\FollowUpTask;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The daily follow-up summary. Holds only plain values (no models), and is sent synchronously by the scheduler, so
 * nothing tenant-scoped has to be re-read later without a tenant context.
 */
class TaskReminderDigest extends Notification
{
    /** @var list<array{title: string, lead: string, lead_url: string, due: string, overdue: bool}> */
    public array $items;

    /** @param  Collection<int, FollowUpTask>  $tasks */
    public function __construct(public string $workspace, Collection $tasks, public int $total, public int $overdue, string $timezone)
    {
        $now = now();
        $items = [];
        foreach ($tasks as $t) {
            /** @var CarbonInterface $due */
            $due = $t->due_at;
            /** @var Lead|null $lead */
            $lead = $t->lead;
            $items[] = [
                'title' => (string) $t->title,
                'lead' => (string) ($lead->full_name ?? ''),
                'lead_url' => url('/leads/'.($lead->public_id ?? '')),
                'due' => $due->copy()->setTimezone($timezone)->format('D j M, H:i'),
                'overdue' => $due->lessThan($now),
            ];
        }
        $this->items = $items;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dueToday = $this->total - $this->overdue;
        $mail = (new MailMessage)
            ->subject("{$this->workspace}: {$this->total} follow-up".($this->total === 1 ? '' : 's').' need attention')
            ->greeting('Your follow-ups for today')
            ->line("In **{$this->workspace}** you have {$this->overdue} overdue and {$dueToday} due later today.");

        foreach ($this->items as $item) {
            $mail->line(($item['overdue'] ? '**Overdue** · ' : '').e($item['due']).' · '.e($item['title']).' — ['.e($item['lead']).']('.$item['lead_url'].')');
        }
        if ($this->total > count($this->items)) {
            $mail->line('…and '.($this->total - count($this->items)).' more.');
        }

        return $mail
            ->action('Open follow-ups', url('/tasks'))
            ->line('Links open the workspace you are signed in to; switch to '.e($this->workspace).' first if needed.')
            ->line('You can turn these e-mails off on your profile page.');
    }
}
