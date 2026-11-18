<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\BreakingNews;
use App\Models\FootballMatch;
use App\Models\GriotChallenge;
use App\Models\GuestPrediction;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\Standing;
use App\Models\StoryVideo;
use App\Models\User;
use App\Models\UserPrediction;
use App\Services\Agent\AgentMedia;
use App\Services\BreakingNewsDesk;
use App\Services\RadioVideo;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowFacts;
use App\Services\Slideshow\Slideshows;
use App\Services\SocialQueue;
use App\Services\Story\StoryVideos;
use App\Support\AfricanScope;
use App\Support\ClubName;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The agent API (/api/agent, bearer token, docs/agent-api.md): the site's content data read-only,
 * renders, the social queue and draft articles. No user records, payments, secrets or deletes.
 */
class AgentController extends Controller
{
    public function __construct(private SocialQueue $queue) {}

    // ------------------------------------------------------------------ reads

    /** GET /api/agent/matches?status=live|ft|upcoming&from=&to=&competition=&limit= */
    public function matches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['live', 'ft', 'upcoming'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'competition' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('agent.max_limit', 100)],
        ]);
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league']);
        match ($data['status'] ?? null) {
            'live' => $query->whereIn('status', FootballMatch::LIVE_STATUSES),
            'ft' => $query->whereIn('status', ['FINISHED', 'FT', 'AWARDED']),
            'upcoming' => $query->whereIn('status', ['SCHEDULED', 'TIMED'])->where('match_date', '>=', now('UTC')->subHours(2)->format('Y-m-d H:i:s')),
            default => null,
        };
        // Dates are UTC days, like matches.match_date.
        if (!empty($data['from'])) {
            $query->where('match_date', '>=', Carbon::parse($data['from'], 'UTC')->startOfDay()->format('Y-m-d H:i:s'));
        }
        if (!empty($data['to'])) {
            $query->where('match_date', '<=', Carbon::parse($data['to'], 'UTC')->endOfDay()->format('Y-m-d H:i:s'));
        }
        if (!empty($data['competition'])) {
            $c = $data['competition'];
            $query->whereHas('league', fn ($q) => $q->where('short_code', $c)->orWhere('espn_slug', $c)->orWhere('name', $c)
                ->when(ctype_digit($c), fn ($q) => $q->orWhere('id', (int) $c)));
        }
        $upcoming = ($data['status'] ?? null) === 'upcoming';
        $matches = $query->orderBy('match_date', $upcoming ? 'asc' : 'desc')->limit((int) ($data['limit'] ?? 30))->get();
        $events = MatchEvent::whereIn('match_id', $matches->pluck('id'))->orderBy('minute')->orderBy('extra_minute')->orderBy('id')->get()->groupBy('match_id');
        $standings = $this->standings($matches->pluck('league_id')->filter()->unique()->all());

        return response()->json([
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'count' => $matches->count(),
            'matches' => $matches->map(fn (FootballMatch $m) => $this->match($m, $events->get($m->id, collect()), $standings[$m->league_id] ?? []))->values(),
        ]);
    }

    /** GET /api/agent/players?country=&club=&african=true&limit= */
    public function players(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'country' => ['nullable', 'string', 'max:60'], 'club' => ['nullable', 'string', 'max:80'],
            'african' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('agent.max_limit', 100)],
        ]);
        $query = Player::with(['team.league'])->active();
        if (AfricanScope::heritageReady()) {
            $query->with('heritages');
        }
        if (in_array($data['african'] ?? null, ['true', '1'], true)) {
            AfricanScope::scope($query);
        }
        if (!empty($data['country'])) {
            $c = $data['country'];
            $query->where(function ($q) use ($c) {
                $q->where('nationality_code', strtoupper($c))->orWhere('nationality', $c);
                if (AfricanScope::heritageReady()) {
                    $q->orWhereHas('heritages', fn ($h) => $h->confirmed()->where(fn ($h) => $h->where('country_code', strtoupper($c))->orWhere('country', $c)));
                }
            });
        }
        if (!empty($data['club'])) {
            $club = $data['club'];
            $query->whereHas('team', fn ($q) => ctype_digit($club) ? $q->whereKey((int) $club) : $q->where('name', 'like', '%'.$club.'%'));
        }
        if (!empty($data['q'])) {
            // By name: every word must appear ("salah", "mohamed salah", "M. Salah" all find Mohamed Salah).
            foreach (preg_split('/[\s.]+/u', trim($data['q'])) ?: [] as $word) {
                if (mb_strlen($word) > 1) {
                    $query->where('name', 'like', '%'.$word.'%');
                }
            }
        }
        $players = $query->orderBy('name')->limit((int) ($data['limit'] ?? 50))->get();

        return response()->json(['count' => $players->count(), 'players' => $players->map(fn (Player $p) => $this->player($p))->values()]);
    }

    /** GET /api/agent/radio?latest=true&limit= */
    public function radio(Request $request): JsonResponse
    {
        if (!Schema::hasTable('radio_bulletins')) {
            return response()->json(['editions' => []]);
        }
        $latest = in_array($request->query('latest'), ['true', '1'], true);
        $limit = $latest ? 1 : min((int) $request->query('limit', 10), 30);
        $editions = RadioBulletin::published()->orderByDesc('published_at')->limit($limit)->get();

        return response()->json(['editions' => $editions->map(fn (RadioBulletin $b) => [
            'id' => $b->id,
            'title' => $b->title,
            'edition' => $b->editionLabel(),
            'published_at' => $b->published_at?->utc()->toIso8601ZuluString(),
            'duration_seconds' => (int) $b->duration_seconds,
            'audio_url' => $b->audioFile() ? SocialQueue::https($b->audioUrl()) : null,
            'page_url' => SocialQueue::https(route('radio.show', $b)),
            'video_url' => RadioVideo::columnsReady() && $b->videoReady() ? SocialQueue::https($b->videoUrl()) : null,
            'transcript' => (string) $b->script,
            'running_order' => collect($b->segments)->values()->map(function ($s, $i) use ($b) {
                $facts = collect($b->facts)->keyBy('key');

                return [
                    'position' => $i + 1,
                    'text' => $s['text'] ?? null,
                    'start' => data_get($b->audio_cues, "{$i}.start"),
                    'end' => data_get($b->audio_cues, "{$i}.end"),
                    'stories' => collect((array) ($s['fact_keys'] ?? []))->map(fn ($k) => collect($facts->get($k))->only(['key', 'kind', 'headline', 'source', 'url', 'rumour'])->all())->values(),
                ];
            }),
            'disclosure' => (string) config('radio.disclosure'),
        ])->values()]);
    }

    /** GET /api/agent/articles?status=&limit= (drafts included: the agent reads all content). */
    public function articles(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('agent.max_limit', 100)]]);
        $blogs = Blog::when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('updated_at')->limit((int) ($data['limit'] ?? 30))->get();

        return response()->json(['articles' => $blogs->map(fn (Blog $b) => $this->article($b))->values()]);
    }

    /** GET /api/agent/predictions: per-match entries and results, and Beat the Griot rounds. Counts only, no personal data. */
    public function predictions(Request $request): JsonResponse
    {
        $days = min(60, max(1, (int) $request->query('days', 14)));
        $since = now('UTC')->subDays($days)->format('Y-m-d H:i:s');
        $matchIds = UserPrediction::whereHas('match', fn ($q) => $q->where('match_date', '>=', $since))->distinct()->pluck('match_id')
            ->merge(Schema::hasTable('guest_predictions') ? GuestPrediction::whereHas('match', fn ($q) => $q->where('match_date', '>=', $since))->distinct()->pluck('match_id') : [])
            ->unique()->values();
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->whereIn('id', $matchIds)->orderByDesc('match_date')->get();
        $rows = $matches->map(function (FootballMatch $m) {
            $users = UserPrediction::where('match_id', $m->id)->get();
            $guests = Schema::hasTable('guest_predictions') ? GuestPrediction::where('match_id', $m->id)->whereNull('claimed_by')->get() : collect();
            $scores = $users->map(fn ($p) => [$p->home_score_prediction, $p->away_score_prediction])->concat($guests->map(fn ($g) => [$g->home_score, $g->away_score]))
                ->filter(fn ($s) => $s[0] !== null && $s[1] !== null);
            $finished = SlideshowFacts::finished($m);

            return [
                'match' => $this->matchSummary($m),
                'entries' => ['accounts' => $users->count(), 'guests' => $guests->count()],
                'calls' => [
                    'home_win' => $scores->filter(fn ($s) => $s[0] > $s[1])->count(),
                    'draw' => $scores->filter(fn ($s) => $s[0] === $s[1])->count(),
                    'away_win' => $scores->filter(fn ($s) => $s[0] < $s[1])->count(),
                    'top_scores' => $scores->map(fn ($s) => "{$s[0]}-{$s[1]}")->countBy()->sortDesc()->take(5)->all(),
                ],
                'result' => $finished ? [
                    'score' => "{$m->home_score}-{$m->away_score}",
                    'exact' => $scores->filter(fn ($s) => (int) $s[0] === (int) $m->home_score && (int) $s[1] === (int) $m->away_score)->count(),
                    'right_result' => $scores->filter(fn ($s) => ($s[0] <=> $s[1]) === ((int) $m->home_score <=> (int) $m->away_score))->count(),
                    'points_awarded' => (int) $users->sum('points_earned'),
                ] : null,
            ];
        });
        $rounds = Schema::hasTable('griot_challenges')
            ? GriotChallenge::with(['picks.match.homeTeam', 'picks.match.awayTeam'])->latest('first_kickoff')->limit(5)->get()->map(fn ($c) => [
                'id' => $c->id, 'status' => $c->status, 'first_kickoff_utc' => Carbon::parse($c->first_kickoff, 'UTC')->toIso8601ZuluString(),
                'griot_points' => $c->griot_points, 'players' => (int) $c->players, 'beat_the_griot' => (int) $c->beaters,
                'winners_drawn' => $c->winners()->count(),
                'calls' => $c->picks->map(fn ($p) => ['match' => $p->match ? $this->matchSummary($p->match) : null, 'griot_call' => "{$p->home_pick}-{$p->away_pick}"])->values(),
            ])->values()
            : [];

        return response()->json(['days' => $days, 'matches' => $rows->values(), 'beat_the_griot' => $rounds]);
    }

    /** GET /api/agent/analytics?days=1|7|30: visits by UTM source and campaign, and signups by source. */
    public function analytics(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in(['1', '7', '30'])]])['days'] ?? 7);
        $from = now()->subDays($days - 1)->toDateString();
        $visits = Schema::hasTable('utm_visits') ? DB::table('utm_visits')->where('day', '>=', $from)->get() : collect();
        $group = fn ($rows, string $key) => $rows->groupBy($key)->map(fn ($g, $k) => ['value' => $k === '' ? '(none)' : $k, 'visits' => (int) $g->sum('visits')])
            ->sortByDesc('visits')->values();
        $signups = Schema::hasColumn('users', 'utm_source')
            ? User::where('created_at', '>=', now()->subDays($days - 1)->startOfDay())->get(['utm_source', 'utm_campaign'])
            : collect();

        return response()->json([
            'days' => $days,
            'from' => $from,
            'to' => now()->toDateString(),
            'timezone' => config('app.timezone'),
            'note' => 'Visits are page loads that carry utm_source, once per session per tag set, counted on this site since the agent release. Sessions and clicks without UTM tags are in Microsoft Clarity.',
            'visits' => [
                'total' => (int) $visits->sum('visits'),
                'by_source' => $group($visits, 'utm_source'),
                'by_campaign' => $group($visits, 'utm_campaign'),
                'by_source_campaign' => $visits->groupBy(fn ($r) => $r->utm_source.'|'.$r->utm_medium.'|'.$r->utm_campaign)
                    ->map(fn ($g) => ['source' => $g->first()->utm_source, 'medium' => $g->first()->utm_medium ?: null, 'campaign' => $g->first()->utm_campaign ?: null, 'visits' => (int) $g->sum('visits')])
                    ->sortByDesc('visits')->values(),
                'by_day' => $visits->groupBy('day')->map(fn ($g, $d) => ['day' => Carbon::parse($d)->toDateString(), 'visits' => (int) $g->sum('visits')])->sortBy('day')->values(),
            ],
            'signups' => [
                'total' => $signups->count(),
                'by_source' => $signups->groupBy(fn ($u) => $u->utm_source ?: '(none)')->map(fn ($g, $k) => ['value' => $k, 'signups' => $g->count()])->sortByDesc('signups')->values(),
                'by_campaign' => $signups->groupBy(fn ($u) => $u->utm_campaign ?: '(none)')->map(fn ($g, $k) => ['value' => $k, 'signups' => $g->count()])->sortByDesc('signups')->values(),
            ],
        ]);
    }

    // ------------------------------------------------------------------ renders

    /** POST /api/agent/render {type, match_id|episode_id, hook?, banter?, lang?} -> {id, status} */
    public function render(Request $request, StoryVideos $stories, Slideshows $slideshows): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['match_story', 'radio_video', 'slideshow'])],
            'match_id' => ['required_if:type,match_story,slideshow', 'nullable', 'integer'],
            'episode_id' => ['required_if:type,radio_video', 'nullable', 'integer'],
            'hook' => ['nullable', 'string', 'max:120'],
            'banter' => ['nullable', 'string', 'max:180'],
            'lang' => ['nullable', Rule::in((array) config('story.languages', ['en']))],
        ]);
        if ($data['type'] === 'slideshow') {
            if (!Slideshows::enabled()) {
                return response()->json(['message' => 'Slideshows are off (SLIDESHOW_ENABLED) or not migrated.'], 503);
            }
            $match = FootballMatch::find($data['match_id']);
            if (!$match || !SlideshowFacts::finished($match)) {
                return response()->json(['message' => 'Slideshows are rendered for finished matches; that match is unknown or not finished.'], 422);
            }
            $show = Slideshow::firstOrCreate(['key' => "post_match:{$match->id}"], ['match_id' => $match->id, 'type' => 'post_match', 'status' => 'pending', 'priority' => 50]);
            if (in_array($show->status, ['ready', 'failed'], true)) {
                $slideshows->regenerate($show);
            }

            return response()->json(['id' => "slideshow:{$show->id}", 'status' => $show->fresh()->status,
                'status_url' => SocialQueue::https(route('api.agent.render.show', "slideshow:{$show->id}"))], 202);
        }
        if (!StoryVideos::enabled()) {
            return response()->json(['message' => 'Story videos are off (STORY_VIDEOS) or not migrated.'], 503);
        }
        if ($data['type'] === 'match_story') {
            $match = FootballMatch::find($data['match_id']);
            if (!$match || !SlideshowFacts::finished($match)) {
                return response()->json(['message' => 'Match stories need a finished match; that match is unknown or not finished.'], 422);
            }
            $subject = $match->id;
        } else {
            $bulletin = Schema::hasTable('radio_bulletins') ? RadioBulletin::published()->find($data['episode_id']) : null;
            if (!$bulletin) {
                return response()->json(['message' => 'Unknown or unpublished radio episode.'], 422);
            }
            $subject = $bulletin->id;
        }
        $overrides = array_intersect_key($request->only(['hook', 'banter']), $request->all());
        $story = $stories->request($data['type'], $subject, $data['lang'] ?? 'en', $overrides, 'api', $request->user()->id);

        return response()->json(['id' => $story->queueId(), 'status' => $story->status,
            'status_url' => SocialQueue::https(route('api.agent.render.show', $story->queueId()))], 202);
    }

    /** GET /api/agent/render/{id}: story:<id> or slideshow:<id>. */
    public function renderStatus(string $id): JsonResponse
    {
        [$kind, $num] = explode(':', $id, 2);
        if ($kind === 'slideshow') {
            $show = Slideshow::ready() ? Slideshow::find((int) $num) : null;
            if (!$show) {
                return response()->json(['message' => 'Unknown render.'], 404);
            }

            return response()->json(['id' => $id, 'type' => 'slideshow', 'status' => $show->status, 'approved' => (bool) $show->approved,
                'note' => $show->status === 'ready' && !$show->approved ? 'Rendered; an editor approves slideshows before they reach the social queue.' : null,
                'error' => $show->error, 'duration_seconds' => $show->duration_seconds,
                'mp4_url' => $show->videoUrl() ? SocialQueue::https($show->videoUrl()) : null,
                'cover_url' => $show->posterUrl() ? SocialQueue::https($show->posterUrl()) : null, 'updated_at' => $show->updated_at?->utc()->toIso8601ZuluString()]);
        }
        $story = StoryVideo::ready() ? StoryVideo::with(['match.homeTeam', 'match.awayTeam', 'bulletin'])->find((int) $num) : null;
        if (!$story) {
            return response()->json(['message' => 'Unknown render.'], 404);
        }

        return response()->json($this->story($story));
    }

    // ------------------------------------------------------------------ social queue and articles

    public function socialQueue(): JsonResponse
    {
        if (!SocialQueue::ready()) {
            return response()->json(['message' => 'The social queue needs its migration.'], 503);
        }

        return response()->json(['generated_at' => now()->utc()->toIso8601ZuluString(), 'days' => (int) config('social.queue_days', 14),
            'items' => $this->queue->items()->values()]);
    }

    public function socialQueuePosted(Request $request, string $id): JsonResponse
    {
        if (!SocialQueue::ready()) {
            return response()->json(['message' => 'The social queue needs its migration.'], 503);
        }
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(SocialQueue::CHANNELS)],
            'external_post_id' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'url', 'max:2048'],
        ]);
        $item = $this->queue->markPosted($id, $data['channel'], $data['external_post_id'] ?? null, $data['url'] ?? null);

        return $item ? response()->json($item) : response()->json(['message' => 'Unknown queue item.'], 404);
    }

    /** POST /api/agent/articles: always a draft; an editor publishes it from /admin/blogs. */
    public function storeArticle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:100000'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'match_id' => ['nullable', 'integer'],
            'sources' => ['nullable', 'array', 'max:20'], 'sources.*' => ['string', 'url', 'max:2048'],
            'publish' => ['nullable', 'boolean'],
        ]);
        $publish = (bool) ($data['publish'] ?? false);
        $slug = Str::slug($data['title']) ?: 'draft';
        $base = $slug;
        for ($i = 2; Blog::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        $blog = Blog::create([
            'title' => $data['title'], 'slug' => $slug, 'content' => $data['content'], 'excerpt' => $data['excerpt'] ?? null,
            'status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? now() : null, 'author_name' => $request->user()->name,
            'metadata' => array_filter(['source' => 'agent', 'created_by' => $request->user()->id, 'match_id' => $data['match_id'] ?? null,
                'sources' => $data['sources'] ?? null]),
        ]);

        return response()->json($this->article($blog->fresh()), 201);
    }

    /**
     * PATCH /api/agent/articles/{id}: edit, publish or unpublish one of the agent's own articles.
     * Articles a person wrote stay read-only to the agent.
     */
    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $blog = Blog::find($id);
        if (!$blog) {
            return response()->json(['message' => 'Unknown article.'], 404);
        }
        if (data_get($blog->metadata, 'source') !== 'agent') {
            return response()->json(['message' => 'The agent can only change articles it created.'], 403);
        }
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:100000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'publish' => ['sometimes', 'boolean'],
        ]);
        $fields = collect($data)->only(['title', 'content', 'excerpt'])->all();
        if (array_key_exists('publish', $data)) {
            $fields['status'] = $data['publish'] ? 'published' : 'draft';
            $fields['published_at'] = $data['publish'] ? ($blog->published_at ?? now()) : null;
        }
        $blog->update($fields);

        return response()->json($this->article($blog->fresh()));
    }

    /**
     * POST /api/agent/players/{id}/photo {source_url}: a Wikimedia Commons file for this player, checked
     * like every library photo (CC0, public domain, CC BY, CC BY-SA; 700 px or wider), saved, and used by
     * the next story or slideshow. 422 with the reason for anything else.
     */
    public function playerPhoto(Request $request, int $id, CommonsPhotos $photos): JsonResponse
    {
        $player = Player::with('team')->find($id);
        if (!$player) {
            return response()->json(['message' => 'Unknown player.'], 404);
        }

        return $this->attachPhoto(fn (string $url) => $photos->attach($player, $url), $request, ['player_id' => $player->id, 'name' => $player->name]);
    }

    /**
     * POST /api/agent/player-photos {name, source_url}: the same for a player we have no row for (national
     * teams), kept in the name library the stories read.
     */
    public function namedPlayerPhoto(Request $request, CommonsPhotos $photos): JsonResponse
    {
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'min:3', 'max:80', 'regex:/\S\s+\S/u']])['name']);

        return $this->attachPhoto(fn (string $url) => $photos->attachName($name, $url), $request, ['player_id' => null, 'name' => $name]);
    }

    private function attachPhoto(callable $attach, Request $request, array $who): JsonResponse
    {
        $url = (string) $request->validate(['source_url' => ['required', 'string', 'url', 'max:2048']])['source_url'];
        try {
            $photo = $attach($url);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($who + ['photo' => $this->photoRecord($photo)], 201);
    }

    /** POST /api/agent/slideshows/{id}/approve: put a ready slideshow live (pages, social queue, YouTube), as an editor would. */
    public function approveSlideshow(Request $request, int $id, Slideshows $slideshows): JsonResponse
    {
        $show = Slideshow::ready() ? Slideshow::find($id) : null;
        if (!$show) {
            return response()->json(['message' => 'Unknown slideshow.'], 404);
        }
        if (!$slideshows->approve($show, $request->user())) {
            return response()->json(['message' => 'Only a ready slideshow with its video can be approved.', 'status' => $show->status], 409);
        }

        return response()->json(['id' => "slideshow:{$show->id}", 'approved' => true, 'page_url' => SocialQueue::https(route('slideshows.show', $show))]);
    }

    /** GET /api/agent/radio/{id}: any edition's state, including queued and failed ones and why they failed. */
    public function radioEdition(int $id): JsonResponse
    {
        $b = Schema::hasTable('radio_bulletins') ? RadioBulletin::find($id) : null;

        return $b ? response()->json($this->edition($b)) : response()->json(['message' => 'Unknown radio edition.'], 404);
    }

    /**
     * POST /api/agent/radio/{id}/retry: back into the queue for a failed edition (as Server operations
     * radio-retry). The minute worker writes, checks, voices and (with auto-publish) publishes it.
     */
    public function radioRetry(int $id): JsonResponse
    {
        if (!RadioBulletin::available()) {
            return response()->json(['message' => 'Radio is off (RADIO_ENABLED) or not migrated.'], 503);
        }
        $b = RadioBulletin::find($id);
        if (!$b) {
            return response()->json(['message' => 'Unknown radio edition.'], 404);
        }
        if ($b->status !== 'failed') {
            return response()->json(['message' => 'Only a failed edition can be retried.', 'status' => $b->status], 409);
        }
        if ($b->slotTime()->lt(now('Africa/Lagos')->subHours(6))) {
            return response()->json(['message' => 'This edition is more than six hours old; start a fresh one with POST /api/agent/radio.'], 409);
        }
        $b->update(['status' => 'queued', 'stage' => 'script', 'attempts' => 0, 'failure_reason' => null]);

        return response()->json($this->edition($b->fresh()) + ['status_url' => SocialQueue::https(route('api.agent.radio.show', $b))], 202);
    }

    /**
     * POST /api/agent/radio: a fresh edition outside the 06:00/12:00/18:00 slots, from the facts available
     * now, through the same script checks, fact review and voice. One edition at a time; at most
     * config radio.agent_extra_per_day extra editions a Lagos day.
     */
    public function radioCreate(): JsonResponse
    {
        if (!RadioBulletin::available()) {
            return response()->json(['message' => 'Radio is off (RADIO_ENABLED) or not migrated.'], 503);
        }
        if ($busy = RadioBulletin::whereIn('status', ['queued', 'processing'])->first()) {
            return response()->json(['message' => 'An edition is already being made; wait for it to finish.', 'edition' => $this->edition($busy)], 409);
        }
        $today = now('Africa/Lagos')->format('Y-m-d');
        $extra = RadioBulletin::where('slot', 'like', "{$today}-%-r%")->count();
        $cap = (int) config('radio.agent_extra_per_day', 4);
        if ($extra >= $cap) {
            return response()->json(['message' => "The extra editions for today ({$cap}) are used up."], 429);
        }
        $b = app(\App\Services\RadioService::class)->queue(fresh: true);
        $b->update(['status' => 'queued', 'stage' => 'script', 'attempts' => 0]);

        return response()->json($this->edition($b->fresh()) + ['status_url' => SocialQueue::https(route('api.agent.radio.show', $b))], 202);
    }

    // ------------------------------------------------------------------ media

    /**
     * POST /api/agent/media (multipart: file = an mp4 up to 200 MB, render_id = "story:45" or "slideshow:7",
     * optional): stored at /generated/agent/{uuid}.mp4, served by the host as video/mp4 with byte ranges, and
     * deleted after 7 days. Returns 201 with the public https URL.
     */
    public function mediaStore(Request $request, AgentMedia $media): JsonResponse
    {
        $file = $request->file('file');
        if ($file && !$file->isValid()) {
            // PHP refused the upload (upload_max_filesize / post_max_size on the server, or a partial upload).
            return response()->json(['message' => 'The upload did not arrive: '.$file->getErrorMessage()
                .' The server accepts up to '.ini_get('upload_max_filesize').' per file ('.ini_get('post_max_size').' per request).',
                'errors' => ['file' => [$file->getErrorMessage()]]], $file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE ? 413 : 422);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.AgentMedia::MAX_KB, 'mimetypes:video/mp4,application/mp4'],
            'render_id' => ['nullable', 'string', 'regex:/^(story|slideshow):\d+$/'],
        ], ['file.max' => 'The video may be at most 200 MB.', 'file.mimetypes' => 'Only MP4 video (video/mp4) is accepted.']);
        if (!AgentMedia::looksLikeMp4($file->getRealPath())) {
            return response()->json(['message' => 'Only MP4 video (video/mp4) is accepted.', 'errors' => ['file' => ['The file is not an MP4 container.']]], 422);
        }
        if (!empty($data['render_id']) && !AgentMedia::render($data['render_id'])) {
            return response()->json(['message' => 'Unknown render id.', 'errors' => ['render_id' => ['No render with that id.']]], 422);
        }

        return response()->json($media->store($file, $request->user(), $data['render_id'] ?? null), 201);
    }

    // ------------------------------------------------------------------ breaking news

    /** GET /api/agent/breaking: the item on screen now and the 30 newest items. */
    public function breaking(): JsonResponse
    {
        if (!BreakingNews::ready()) {
            return response()->json(['message' => 'Breaking news is not set up yet (run the migrations).'], 503);
        }
        $current = BreakingNews::current();

        return response()->json([
            'current' => $current ? BreakingNews::with(['author', 'editor', 'ender'])->find($current->id)->toApi() : null,
            'items' => BreakingNews::with(['author', 'editor', 'ender'])->latest('created_at')->latest('id')->limit(30)->get()->map->toApi()->values(),
        ]);
    }

    /**
     * POST /api/agent/breaking {headline, body?, link_url?, image_url?, image_credit?, expires_in_hours?}: live at
     * once at the top of the homepage, match and listen pages (the newest live item wins), logged, and listed on
     * the admin dashboard and /admin/breaking.
     */
    public function breakingStore(Request $request, BreakingNewsDesk $desk): JsonResponse
    {
        if (!BreakingNews::ready()) {
            return response()->json(['message' => 'Breaking news is not set up yet (run the migrations).'], 503);
        }

        return response()->json($desk->create($request->all(), $request->user(), 'agent')->toApi(), 201);
    }

    /** PATCH /api/agent/breaking/{id}: edit a live item (the agent's own; an admin token may edit any). */
    public function breakingUpdate(Request $request, int $id, BreakingNewsDesk $desk): JsonResponse
    {
        $item = $this->breakingItem($request, $id);
        if ($item instanceof JsonResponse) {
            return $item;
        }

        return response()->json($desk->update($item, $request->all(), $request->user())->toApi());
    }

    /** POST /api/agent/breaking/{id}/end: takes the item off the site now. Ending twice is harmless. */
    public function breakingEnd(Request $request, int $id, BreakingNewsDesk $desk): JsonResponse
    {
        $item = $this->breakingItem($request, $id);
        if ($item instanceof JsonResponse) {
            return $item;
        }

        return response()->json($desk->end($item, $request->user())->toApi());
    }

    private function breakingItem(Request $request, int $id): BreakingNews|JsonResponse
    {
        if (!BreakingNews::ready()) {
            return response()->json(['message' => 'Breaking news is not set up yet (run the migrations).'], 503);
        }
        $item = BreakingNews::find($id);
        if (!$item) {
            return response()->json(['message' => 'Unknown breaking news item.'], 404);
        }
        if (!$request->user()->isAdmin() && $item->source !== 'agent') {
            return response()->json(['message' => 'The agent can only change breaking news it posted.'], 403);
        }

        return $item;
    }

    private function edition(RadioBulletin $b): array
    {
        return [
            'id' => $b->id, 'slot' => $b->slot, 'title' => $b->title, 'status' => $b->status, 'stage' => $b->stage,
            'attempts' => (int) $b->attempts, 'failure_reason' => $b->failure_reason,
            'recorded_at' => $b->recorded_at?->utc()->toIso8601ZuluString(), 'published_at' => $b->published_at?->utc()->toIso8601ZuluString(),
            'page_url' => $b->status === 'published' ? SocialQueue::https(route('radio.show', $b)) : null,
        ];
    }

    // ------------------------------------------------------------------ shapes

    private function standings(array $leagueIds): array
    {
        if (!$leagueIds) {
            return [];
        }
        $out = [];
        foreach ($leagueIds as $id) {
            $q = Standing::with('team')->where('league_id', $id);
            if ((clone $q)->where('is_current', true)->exists()) {
                $q->where('is_current', true);
            }
            $out[$id] = $q->orderBy('position')->get()->unique('team_id')->map(fn ($r) => [
                'position' => (int) $r->position, 'team' => ClubName::full($r->team), 'team_id' => $r->team_id, 'played' => $r->matches_played,
                'won' => $r->wins, 'drawn' => $r->draws, 'lost' => $r->losses, 'goal_difference' => $r->goal_difference, 'points' => $r->points,
            ])->values()->all();
        }

        return $out;
    }

    private function matchSummary(FootballMatch $m): array
    {
        return [
            'id' => $m->id,
            'home' => ClubName::full($m->homeTeam), 'away' => ClubName::full($m->awayTeam),
            'competition' => $m->league?->name, 'competition_code' => $m->league?->short_code ?: $m->league?->espn_slug,
            'kickoff_utc' => SlideshowFacts::kickoff($m)->toIso8601ZuluString(),
            'status' => $m->status, 'home_score' => $m->home_score, 'away_score' => $m->away_score,
            'url' => SocialQueue::https(route('matches.show', $m)),
        ];
    }

    private function match(FootballMatch $m, $events, array $table): array
    {
        $lineups = data_get($m->metadata, 'api_football_lineups') ?: data_get($m->metadata, 'espn_lineups');
        $stats = collect(['possession', 'shots', 'shots_on_target', 'corners', 'fouls', 'yellow_cards', 'red_cards'])
            ->mapWithKeys(fn ($s) => [$s => ['home' => $m->{"home_{$s}"}, 'away' => $m->{"away_{$s}"}]])
            ->filter(fn ($v) => $v['home'] !== null || $v['away'] !== null)->all();
        $story = StoryVideo::ready() ? StoryVideo::where('kind', 'match_story')->where('match_id', $m->id)->get() : collect();

        return $this->matchSummary($m) + [
            'minute' => $m->minute,
            'half_time' => $m->home_score_ht !== null ? "{$m->home_score_ht}-{$m->away_score_ht}" : null,
            'venue' => $m->venue_name, 'referee' => $m->referee_name, 'attendance' => $m->attendance,
            'events' => $events->map(fn (MatchEvent $e) => [
                'type' => $e->type, 'minute' => $e->minute, 'extra_minute' => $e->extra_minute,
                'side' => $e->team_id === $m->away_team_id ? 'away' : 'home', 'player' => $e->player_name,
                'assist' => $e->assist_player_name, 'own_goal' => (bool) $e->is_own_goal, 'penalty' => (bool) $e->is_penalty,
            ])->values(),
            'stats' => $stats ?: null,
            'lineups' => $lineups ?: null,
            'standings' => $table ?: null,
            'stories' => $story->map(fn ($s) => ['id' => $s->queueId(), 'lang' => $s->lang, 'status' => $s->status, 'mp4_url' => $s->videoUrl() ? SocialQueue::https($s->videoUrl()) : null])->values(),
        ];
    }

    private function player(Player $p): array
    {
        $season = SlideshowFacts::playerSeason($p, $p->team?->league);
        $photo = data_get($p->metadata, 'commons_photo');
        $licensed = is_array($photo) && ($photo['status'] ?? null) === 'ok';

        return [
            'id' => $p->id,
            'name' => $p->name,
            'position' => $p->position,
            'shirt_number' => $p->shirt_number,
            'date_of_birth' => $p->date_of_birth?->toDateString(),
            'club' => $p->team ? ['id' => $p->team->id, 'name' => ClubName::full($p->team), 'league' => $p->team->league?->name] : null,
            'nationality' => $p->nationality, 'nationality_code' => $p->nationality_code,
            'in_african_scope' => AfricanScope::inScope($p),
            'african_label' => AfricanScope::label($p),
            'heritage' => AfricanScope::heritageReady() ? $p->heritages->map(fn ($h) => [
                'country' => $h->country, 'country_code' => $h->country_code, 'kind' => $h->kind ?? 'descent', 'status' => $h->status,
                'source_url' => $h->source_url, 'source_note' => $h->source_note,
            ])->values() : [],
            'season' => $season,
            'photo' => $licensed ? $this->photoRecord($photo) + ['page_photo_url' => AfricanScope::inScope($p) ? SocialQueue::https(route('discovery.photo', $p)) : null] : null,
            'page_url' => AfricanScope::inScope($p) ? SocialQueue::https(route('discovery.show', $p)) : null,
        ];
    }

    /** A stored Commons photo as the API shows it. */
    private function photoRecord(array $photo): array
    {
        return [
            'url' => $photo['url'] ?? null, 'credit' => $photo['credit'] ?? null, 'licence' => $photo['licence'] ?? null,
            'licence_url' => $photo['licence_url'] ?? null, 'source_url' => $photo['source_url'] ?? null, 'artist' => $photo['artist'] ?? null,
            'title' => $photo['title'] ?? null, 'library' => 'Wikimedia Commons (licence-checked)',
        ];
    }

    private function article(Blog $b): array
    {
        return [
            'id' => $b->id, 'title' => $b->title, 'slug' => $b->slug, 'status' => $b->status, 'excerpt' => $b->excerpt,
            'content' => $b->content, 'author' => $b->author_name,
            'published_at' => $b->published_at?->utc()->toIso8601ZuluString(), 'updated_at' => $b->updated_at?->utc()->toIso8601ZuluString(),
            'url' => $b->status === 'published' ? SocialQueue::https(route('blogs.show', $b)) : null,
            'source' => data_get($b->metadata, 'source'),
        ];
    }

    private function story(StoryVideo $s): array
    {
        return [
            'id' => $s->queueId(), 'type' => $s->kind, 'lang' => $s->lang, 'status' => $s->status, 'attempts' => $s->attempts,
            'error' => $s->error, 'title' => $s->title(), 'hook' => $s->hookLine(), 'banter' => $s->banterLine(),
            'match_id' => $s->match_id, 'episode_id' => $s->radio_bulletin_id,
            'duration_seconds' => $s->duration_seconds,
            'mp4_url' => $s->videoUrl() ? SocialQueue::https($s->videoUrl()) : null,
            'cover_url' => $s->coverUrl() ? SocialQueue::https($s->coverUrl()) : null,
            'captions' => $s->captions, 'photo_credit' => $s->photo['credit'] ?? null,
            'social_queue_id' => $s->status === 'ready' ? $s->queueId() : null,
            'rendered_at' => $s->rendered_at?->utc()->toIso8601ZuluString(), 'updated_at' => $s->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
