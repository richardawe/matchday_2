<?php

namespace App\Services\Alerts;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\InboxMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/** Delivers a message to users: an inbox entry each, and a push to every phone that allowed it. */
class Alerts
{
    public function __construct(private WebPush $push) {}

    /** Off until the migration has run. */
    public function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('notifications') && Schema::hasTable('push_subscriptions') && Schema::hasTable('match_alerts');
    }

    public function pushReady(): bool
    {
        return $this->ready() && $this->push->configured();
    }

    /**
     * @param Collection<int, User> $users
     * @return array{inbox: int, pushed: int}
     */
    public function deliver(Collection $users, InboxMessage $message, bool $push = true, ?string $topic = null): array
    {
        $users = $users->filter()->unique('id')->values();
        if ($users->isEmpty() || !$this->ready()) {
            return ['inbox' => 0, 'pushed' => 0];
        }
        foreach ($users->chunk(200) as $chunk) {
            Notification::sendNow($chunk, $message);
        }
        $pushed = 0;
        if ($push && $this->push->configured()) {
            $payload = ['title' => $message->title, 'body' => $message->body, 'url' => $message->url ?? url('/notifications'),
                'tag' => $topic ?? $message->kind, 'icon' => asset('icons/icon-192.png')];
            $users->pluck('id')->chunk(500)->each(function ($ids) use ($payload, $topic, &$pushed) {
                $pushed += $this->push->send(PushSubscription::whereIn('user_id', $ids)->get(), $payload, $topic)['sent'];
            });
        }

        return ['inbox' => $users->count(), 'pushed' => $pushed];
    }
}
