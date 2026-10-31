<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\EmailSubscriber;
use App\Models\User;
use App\Support\Attribution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Signups: totals, the last 30 days day by day, where people came from and who went Premium. */
class UserController extends Controller
{
    private const DAYS = 30;

    public function index()
    {
        $start = now()->subDays(self::DAYS - 1)->startOfDay();
        $tracked = Schema::hasColumn('users', 'utm_source');
        $recent = User::where('created_at', '>=', $start)
            ->get($tracked ? ['id', 'created_at', 'subscription_status', 'premium_until', 'utm_source', 'utm_medium', 'utm_campaign', 'signup_referrer'] : ['id', 'created_at', 'subscription_status', 'premium_until']);
        $premiumStarts = $this->premiumStarts($start);

        $days = collect(range(0, self::DAYS - 1))->map(function (int $i) use ($start, $recent, $premiumStarts) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');

            return [
                'date' => $date,
                'signups' => $recent->filter(fn ($u) => $u->created_at?->format('Y-m-d') === $date)->count(),
                'premium' => (int) ($premiumStarts[$date] ?? 0),
            ];
        })->reverse()->values();

        $total = User::count();
        $premium = User::where('subscription_status', 'active')
            ->where(fn ($q) => $q->whereNull('premium_until')->orWhere('premium_until', '>', now()))->count();

        return view('admin.users.index', [
            'total' => $total,
            'today' => $days->first()['signups'],
            'week' => $days->take(7)->sum('signups'),
            'month' => $days->sum('signups'),
            'days' => $days,
            'peak' => max(1, $days->max('signups')),
            'premium' => $premium,
            'premiumMonth' => $days->sum('premium'),
            'conversion' => $total ? round(100 * $premium / $total, 1) : 0,
            'tracked' => $tracked,
            'sources' => $tracked ? $this->sources($recent) : collect(),
            'latest' => User::latest()->take(20)->get(),
            'emails' => $this->emails($start),
        ]);
    }

    /** Signups in the window grouped by source / medium / campaign, with how many are Premium now. */
    private function sources(Collection $users): Collection
    {
        return $users->groupBy(fn ($u) => implode('|', [Attribution::label($u->utm_source, $u->signup_referrer), $u->utm_medium ?? '', $u->utm_campaign ?? '']))
            ->map(function (Collection $group, string $key) {
                [$source, $medium, $campaign] = explode('|', $key);

                return [
                    'source' => $source, 'medium' => $medium ?: '—', 'campaign' => $campaign ?: '—',
                    'signups' => $group->count(),
                    'premium' => $group->filter(fn ($u) => $u->isPremium())->count(),
                ];
            })->sortByDesc('signups')->values();
    }

    /** Footer email signups: totals and where the last 30 days came from. */
    private function emails($start): ?array
    {
        if (!EmailSubscriber::ready()) {
            return null;
        }
        $recent = EmailSubscriber::where('created_at', '>=', $start)->get(['source', 'utm_source']);

        return [
            'active' => EmailSubscriber::active()->count(),
            'month' => $recent->count(),
            'sources' => $recent->countBy(fn ($s) => ($s->utm_source ?: 'direct').' · '.($s->source ?: '—'))->sortDesc()->take(10),
        ];
    }

    /** Paid Premium memberships per Lagos day. */
    private function premiumStarts($start): array
    {
        return CommerceOrder::where('status', 'paid')->where('paid_at', '>=', $start)
            ->whereHas('items.product', fn ($q) => $q->where('type', 'membership'))
            ->get(['id', 'paid_at'])
            ->countBy(fn ($order) => $order->paid_at->format('Y-m-d'))
            ->all();
    }
}
