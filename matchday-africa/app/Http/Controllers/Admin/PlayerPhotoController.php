<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * /admin/player-photos: which clubs have player photos (players:photos, API-Football), and a gallery
 * per club with the players still missing one, so they can be checked before a matchday.
 */
class PlayerPhotoController extends Controller
{
    public function index(Request $request)
    {
        $teams = $this->teams();
        $counts = Player::whereIn('team_id', $teams->pluck('id'))->where('is_active', true)
            ->selectRaw('team_id, count(*) as players, sum(case when photo_url is not null then 1 else 0 end) as photos')
            ->groupBy('team_id')->get()->keyBy('team_id');
        $rows = $teams->map(function (Team $team) use ($counts) {
            $meta = is_array($team->metadata) ? $team->metadata : [];

            return [
                'team' => $team,
                'players' => (int) ($counts[$team->id]->players ?? 0),
                'photos' => (int) ($counts[$team->id]->photos ?? 0),
                'linked' => !empty($meta['api_football_team_id']),
                'filled_at' => $meta['api_football_photos_at'] ?? null,
            ];
        })->sortBy([fn ($a, $b) => $b['photos'] <=> $a['photos'], fn ($a, $b) => strcmp($a['team']->name, $b['team']->name)])->values();

        $selected = $request->integer('team') ? $teams->firstWhere('id', $request->integer('team')) ?? Team::find($request->integer('team')) : null;
        $players = $selected
            ? Player::where('team_id', $selected->id)->orderByDesc('is_active')->orderByRaw('photo_url is null')->orderBy('name')->get()
            : collect();

        return view('admin.player-photos.index', [
            'rows' => $rows,
            'selected' => $selected,
            'players' => $players,
            'configured' => (string) config('services.api_football.key') !== '',
            'totals' => ['clubs' => $rows->where('photos', '>', 0)->count(), 'photos' => $rows->sum('photos'), 'players' => $rows->sum('players')],
        ]);
    }

    /** Clubs in the photo leagues (API_FOOTBALL_PHOTO_LEAGUES) by their recent fixtures, plus any club that has photos. */
    private function teams(): Collection
    {
        $codes = array_keys(config('services.api_football.photo_leagues', []));
        $leagues = League::whereIn('short_code', $codes)->orWhereIn('espn_slug', $codes)->get();
        $ids = collect();
        foreach ($leagues as $league) {
            $ids = $ids->merge($league->matches()->where('match_date', '>=', now()->subMonths(4))->pluck('home_team_id'))
                ->merge($league->matches()->where('match_date', '>=', now()->subMonths(4))->pluck('away_team_id'));
        }
        $ids = $ids->merge(Player::whereNotNull('photo_url')->distinct()->pluck('team_id'));

        return Team::whereIn('id', $ids->filter()->unique())->orderBy('name')->get();
    }
}
