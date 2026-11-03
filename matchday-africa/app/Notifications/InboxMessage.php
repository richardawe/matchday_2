<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One inbox entry: a match alert or a message from Matchday Africa. Stored in `notifications`
 * (the bell and /notifications); the push to the user's phones is sent separately, in batches,
 * by App\Services\Alerts\Alerts.
 */
class InboxMessage extends Notification
{
    public function __construct(public string $kind, public string $title, public string $body, public ?string $url = null, public array $extra = []) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url] + $this->extra;
    }
}
