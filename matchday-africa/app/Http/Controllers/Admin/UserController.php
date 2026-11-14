<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\EmailSubscriber;
use App\Models\GuestPrediction;
use App\Models\User;
use App\Support\Attribution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Signups: totals, the last 30 days day by day, where people came from (first touch, see
 * App\Support\Attribution) and who went Premium. The source tables follow a 7, 28 or 30 day
 * window; the totals and the chart always cover 30 days. Days are app-timezone (Lagos) days.
 */
class UserController extends Controller
{
    private const DAYS = 30;
    public const WINDOWS = [7, 28, 30];

    public function index(Request $request)
    {
        $window = $this->window($request);
        $start = now()->subDays(self::DAYS - 1)->startOfDay();
        $windowStart = now()->subDays($window - 1)->startOfDay();
        $tracked = Schema::hasColumn('users', 'utm_source');
        $recent = $this->users($start, $tracked);
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
        $inWindow = $recent->filter(fn ($u) => $u->created_at && $u->created_at->gte($windowStart))->values();

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
            'content' => $tracked && Schema::hasColumn('users', 'utm_content'),
            'window' => $window,
            'windows' => self::WINDOWS,
            'daily' => $tracked ? $this->daily($inWindow, $windowStart, $window) : null,
            'sources' => $tracked ? $this->sources($inWindow) : collect(),
            'landings' => $tracked ? $this->landings($inWindow) : collect(),
            'guests' => $this->guests($windowStart),
            'latest' => User::latest()->take(20)->get(),
            'emails' => $this->emails($start, $windowStart),
            'clarity' => (bool) config('services.clarity.project_id'),
        ]);
    }

    /** The day-by-source table for the selected window as CSV, for the Monday scorecard. */
    public function export(Request $request)
    {
        abort_unless(Schema::hasColumn('users', 'utm_source'), 404);
        $window = $this->window($request);
        $start = now()->subDays($window - 1)->startOfDay();
        $daily = $this->daily($this->users($start, true), $start, $window);
        $name = 'signups-by-source-'.now()->format('Y-m-d').'-'.$window.'d.csv';

        return response()->streamDownload(function () use ($daily) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['date', 'total'], $daily['sources']->all()));
            foreach ($daily['rows'] as $row) {
                fputcsv($out, array_merge([$row['date'], $row['total']], $daily['sources']->map(fn ($s) => $row['by'][$s] ?? 0)->all()));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function window(Request $request): int
    {
        $window = (int) $request->query('window', self::DAYS);

        return in_array($window, self::WINDOWS, true) ? $window : self::DAYS;
    }

    private function users(Carbon $start, bool $tracked): Collection
    {
        $columns = ['id', 'created_at', 'subscription_status', 'premium_until'];
        if ($tracked) {
            $columns = array_merge($columns, ['utm_source', 'utm_medium', 'utm_campaign', 'signup_referrer', 'signup_landing']);
            if (Schema::hasColumn('users', 'utm_content')) {
                $columns[] = 'utm_content';
            }
        }

        return User::where('created_at', '>=', $start)->get($columns);
    }

    /**
     * Signups per day (newest first) split by source label, every day of the window listed (zero when quiet).
     * sources: the labels seen in the window, busiest first.
     */
    private function daily(Collection $users, Carbon $start, int $window): array
    {
        $label = fn ($u) => Attribution::label($u->utm_source, $u->signup_referrer);
        $sources = $users->countBy($label)->map(fn ($n, $s) => [$s, $n])->sortBy([[1, 'desc'], [0, 'asc']])->keys()->values();
        $byDate = $users->groupBy(fn ($u) => $u->created_at?->format('Y-m-d'));
        $rows = collect(range($window - 1, 0))->map(function (int $i) use ($start, $byDate, $label) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $group = $byDate->get($date, collect());

            return ['date' => $date, 'total' => $group->count(), 'by' => $group->countBy($label)->all()];
        })->values();

        return ['sources' => $sources, 'rows' => $rows];
    }

    /** Signups in the window grouped by source / medium / campaign, with Premium now and the days they came in. */
    private function sources(Collection $users): Collection
    {
        return $users->groupBy(fn ($u) => implode('|', [Attribution::label($u->utm_source, $u->signup_referrer), $u->utm_medium ?? '', $u->utm_campaign ?? '']))
            ->map(function (Collection $group, string $key) {
                [$source, $medium, $campaign] = explode('|', $key);

                return [
                    'source' => $source, 'medium' => $medium ?: '—', 'campaign' => $campaign ?: '—',
                    'signups' => $group->count(),
                    'premium' => $group->filter(fn ($u) => $u->isPremium())->count(),
                    'content' => $group->pluck('utm_content')->filter()->countBy()->sortDesc()->take(5)->all(),
                    'days' => $group->countBy(fn ($u) => $u->created_at?->format('Y-m-d'))->sortKeysDesc()->all(),
                ];
            })->sortBy([['source', 'asc'], ['signups', 'desc']])->values();
    }

    /** The pages signups first landed on in the window. */
    private function landings(Collection $users): Collection
    {
        return $users->countBy(fn ($u) => $u->signup_landing ?: '—')->sortDesc()->take(15);
    }

    /** Guest picks in the window by stored UTM source (guest picks keep the source only). */
    private function guests(Carbon $start): ?Collection
    {
        if (!Schema::hasTable('guest_predictions')) {
            return null;
        }

        return GuestPrediction::where('created_at', '>=', $start)->get(['guest_token', 'utm_source', 'claimed_by'])
            ->groupBy(fn ($g) => Attribution::label($g->utm_source))
            ->map(fn (Collection $group, string $source) => [
                'source' => $source,
                'picks' => $group->count(),
                'guests' => $group->pluck('guest_token')->unique()->count(),
                'claimed' => $group->whereNotNull('claimed_by')->pluck('guest_token')->unique()->count(),
            ])->sortByDesc('picks')->values();
    }

    /** Footer email signups: totals over 30 days, and where the selected window's came from. */
    private function emails(Carbon $start, Carbon $windowStart): ?array
    {
        if (!EmailSubscriber::ready()) {
            return null;
        }
        $recent = EmailSubscriber::where('created_at', '>=', $start)->get(['source', 'utm_source', 'utm_campaign', 'created_at']);

        return [
            'active' => EmailSubscriber::active()->count(),
            'month' => $recent->count(),
            'sources' => $recent->filter(fn ($s) => $s->created_at && $s->created_at->gte($windowStart))
                ->countBy(fn ($s) => ($s->utm_source ?: 'direct').($s->utm_campaign ? ' · '.$s->utm_campaign : '').' · '.($s->source ?: '—'))
                ->sortDesc()->take(10),
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
