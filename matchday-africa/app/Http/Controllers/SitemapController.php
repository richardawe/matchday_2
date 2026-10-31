<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml: the main pages, matches (last 90 days and the next 14), reels, articles and
 * teams. Built as a string (production enables PHP short tags, so no XML prolog in Blade) and
 * cached for an hour.
 */
class SitemapController extends Controller
{
    private const MAX_URLS = 45000;

    public function __invoke()
    {
        $xml = Cache::remember('sitemap.xml', 3600, fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function build(): string
    {
        $urls = [];
        $add = function (string $loc, $lastmod = null, string $freq = 'weekly', string $priority = '0.5') use (&$urls) {
            if (count($urls) < self::MAX_URLS) {
                $urls[] = compact('loc', 'lastmod', 'freq', 'priority');
            }
        };

        foreach ([['home', 'hourly', '1.0'], ['matches.index', 'hourly', '0.9'], ['reels.index', 'daily', '0.8'], ['blogs.index', 'daily', '0.8'],
            ['community', 'daily', '0.6'], ['leagues.index', 'weekly', '0.6'], ['teams.index', 'weekly', '0.5'], ['discovery.index', 'weekly', '0.5'],
            ['war.index', 'weekly', '0.5'], ['premium.index', 'monthly', '0.4'], ['shop.index', 'weekly', '0.4'], ['radio.index', 'daily', '0.5'],
            ['terms', 'yearly', '0.1'], ['privacy', 'yearly', '0.1'], ['cookies', 'yearly', '0.1']] as [$name, $freq, $priority]) {
            $add(route($name), null, $freq, $priority);
        }

        $utc = now('UTC');
        $matches = FootballMatch::whereBetween('match_date', [$utc->copy()->subDays(90)->format('Y-m-d H:i:s'), $utc->copy()->addDays(14)->format('Y-m-d H:i:s')])
            ->whereNotNull('home_team_id')->whereNotNull('away_team_id')
            ->orderByDesc('match_date')->limit(20000)->get(['id', 'status', 'metadata', 'updated_at']);
        foreach ($matches as $match) {
            $live = !in_array($match->status, ['FINISHED', 'FT'], true);
            $add(route('matches.show', $match), $match->updated_at, $live ? 'hourly' : 'weekly', '0.7');
        }
        foreach ($matches->filter(fn ($m) => data_get($m->metadata, 'reel.status') === 'ready') as $match) {
            $add(route('reels.show', $match), data_get($match->metadata, 'reel.ready_at') ?? $match->updated_at, 'monthly', '0.6');
        }
        foreach (Blog::published()->latest('published_at')->limit(10000)->get(['id', 'slug', 'updated_at']) as $blog) {
            $add(route('blogs.show', $blog), $blog->updated_at, 'monthly', '0.6');
        }
        foreach (Team::active()->get(['id', 'updated_at']) as $team) {
            $add(route('teams.show', $team), $team->updated_at, 'weekly', '0.4');
        }

        $body = '';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>'
                .($url['lastmod'] ? '<lastmod>'.\Illuminate\Support\Carbon::parse($url['lastmod'])->toAtomString().'</lastmod>' : '')
                .'<changefreq>'.$url['freq'].'</changefreq><priority>'.$url['priority'].'</priority></url>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$body.'</urlset>'."\n";
    }
}
