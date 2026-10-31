<?php

namespace App\Http\Controllers;

use App\Models\EmailSubscriber;
use App\Support\Attribution;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The footer's optional "Get weekend fixtures + results" email signup. */
class EmailSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(EmailSubscriber::ready(), 404);
        $data = $request->validate([
            'email' => 'required|email:rfc|max:255',
            'source' => 'nullable|string|max:255',
            'website' => 'nullable|max:0', // honeypot: people never see or fill it
        ]);
        $email = Str::lower(trim($data['email']));
        $touch = Attribution::read($request);
        $source = $this->path($data['source'] ?? null) ?? $this->path((string) $request->headers->get('referer')) ?? ($touch['signup_landing'] ?? null);

        $subscriber = EmailSubscriber::firstOrNew(['email' => $email]);
        $subscriber->fill([
            'list' => 'weekend',
            'user_id' => $subscriber->user_id ?? $request->user()?->id,
            'consented_at' => now(),
            'unsubscribed_at' => null,
        ]);
        if (!$subscriber->exists) {
            $subscriber->fill(['source' => $source, 'unsubscribe_token' => Str::random(48)] + array_intersect_key($touch, array_flip(['utm_source', 'utm_medium', 'utm_campaign'])));
        }
        $subscriber->save();

        $message = "You're in. Weekend fixtures land on Friday, results on Monday.";
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->to(url()->previous().'#footer-signup')->with('subscribed', $message);
    }

    public function unsubscribe(string $token)
    {
        abort_unless(EmailSubscriber::ready(), 404);
        $subscriber = EmailSubscriber::where('unsubscribe_token', $token)->firstOrFail();
        $subscriber->update(['unsubscribed_at' => $subscriber->unsubscribed_at ?? now()]);

        return view('emails.unsubscribed', ['email' => $subscriber->email]);
    }

    /** The page path a signup came from (never a full URL from another site). */
    private function path(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if ($host && strcasecmp($host, request()->getHost()) !== 0) {
            return null;
        }
        $path = '/'.ltrim((string) parse_url($value, PHP_URL_PATH), '/');

        return Str::limit($path, 250, '');
    }
}
