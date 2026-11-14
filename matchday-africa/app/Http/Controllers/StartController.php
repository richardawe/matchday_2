<?php

namespace App\Http\Controllers;

use App\Services\StartPage;
use Illuminate\Http\Request;

/**
 * /start: the one landing page for social bios and profile links (/tiktok and /watch redirect here,
 * query string kept). CaptureAttribution stores the first touch on arrival; the page carries the
 * same tags onto every Griot, match and signup link.
 *
 * Bio links to paste on each profile:
 *   https://matchday.africa/start?utm_source=tiktok&utm_medium=social&utm_campaign=bio
 *   https://matchday.africa/start?utm_source=instagram&utm_medium=social&utm_campaign=bio
 *   https://matchday.africa/start?utm_source=x&utm_medium=social&utm_campaign=bio
 *   https://matchday.africa/start?utm_source=youtube&utm_medium=social&utm_campaign=bio
 * Posts about a match, player or slideshow keep linking to that page through SocialLink.
 */
class StartController extends Controller
{
    public function show(Request $request, StartPage $page)
    {
        $tags = StartPage::tags($request);
        $upcoming = $page->upcoming();
        $primary = $page->primary($tags, $upcoming);
        $players = $page->players();

        return view('start', [
            'tags' => $tags,
            'primary' => $primary,
            'players' => $players,
            'upcoming' => $primary['kind'] === 'match' ? $upcoming->slice(1)->values() : $upcoming,
            'radio' => $page->radio(),
            'slideshows' => $page->slideshows(),
            'reels' => $page->reels(),
            'return' => $primary['href'] ?? StartPage::carry(route('start', [], false), $tags),
        ]);
    }

    /** /tiktok and /watch: old bio links. Permanent redirect, UTM tags kept. */
    public function alias(Request $request)
    {
        $query = $request->getQueryString();

        return redirect()->to(route('start', [], false).($query ? '?'.$query : ''), 301);
    }
}
