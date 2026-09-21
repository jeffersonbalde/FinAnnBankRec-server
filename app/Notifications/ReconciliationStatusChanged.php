<?php

namespace App\Notifications;

use App\Models\Reconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReconciliationStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Reconciliation $reconciliation,
        public readonly string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reconciliation_id' => $this->reconciliation->id,
            'status' => $this->reconciliation->status->value,
            'status_label' => $this->reconciliation->status->label(),
            'period' => $this->reconciliation->period_start?->toDateString().' – '.$this->reconciliation->period_end?->toDateString(),
            'message' => $this->message,
        ];
    }
}
