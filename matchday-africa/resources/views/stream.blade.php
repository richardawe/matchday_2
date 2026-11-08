<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Matchday Live</title>
@vite(['resources/css/stream.css', 'resources/js/stream.js'])
</head>
<body>
{{-- A fixed 1080×1920 stage, scaled to fit the window. Everything inside is filled by resources/js/stream.js. --}}
<div id="stage" class="st-stage" data-feed="{{ route('stream.feed') }}" data-poll="{{ $pollSeconds }}" data-moment="{{ $momentSeconds }}" data-rotate="{{ $rotateSeconds }}">
    <header class="st-top">
        <div class="st-brand"><span class="st-mono">MD</span><span class="st-word">MATCHDAY LIVE</span></div>
        <div class="st-right">
            <span class="st-updating" id="updating" hidden>Updating…</span>
            <span class="st-live" id="live-pill" hidden><i></i>LIVE</span>
        </div>
    </header>
    <div class="st-ticker" id="ticker" hidden></div>

    <main class="st-body">
        <section class="st-view st-view-live" id="view-live" hidden>
            <div class="st-card st-score">
                <div class="st-score-top"><span id="m-competition"></span><span class="st-clock" id="m-clock"></span></div>
                <div class="st-score-row">
                    <div class="st-side"><span class="st-tile" id="m-home-tile"></span><span class="st-team" id="m-home-name"></span></div>
                    <div class="st-big"><span id="m-home-score"></span><span class="st-dash">–</span><span id="m-away-score"></span></div>
                    <div class="st-side"><span class="st-tile" id="m-away-tile"></span><span class="st-team" id="m-away-name"></span></div>
                </div>
                <div class="st-scorers"><span id="m-home-scorers"></span><span id="m-away-scorers"></span></div>
            </div>

            <div class="st-arena" id="m-arena">
                <img class="st-army st-army-home" id="m-home-art" alt="">
                <img class="st-army st-army-away" id="m-away-art" alt="">
                <span class="st-vs">VS</span>
                <span class="st-army-name st-army-name-home" id="m-home-army"></span>
                <span class="st-army-name st-army-name-away" id="m-away-army"></span>
            </div>

            <div class="st-griot" id="m-griot" hidden>
                <div class="st-griot-top"><span>THE GRIOT SAYS</span><span id="m-griot-clock"></span></div>
                <div class="st-griot-body">
                    <img class="st-griot-face" id="m-griot-face" alt="" hidden>
                    <div><div class="st-griot-head" id="m-griot-head"></div><div class="st-griot-line" id="m-griot-line"></div></div>
                </div>
            </div>

            <div class="st-card st-stats" id="m-stats" hidden></div>

            <div class="st-cta" id="m-cta">
                <div><div class="st-cta-head" id="m-cta-head">BEAT THE GRIOT</div><div class="st-cta-line" id="m-cta-line"></div></div>
                <span class="st-url">matchday.africa</span>
            </div>
            <div class="st-dots" id="m-dots"></div>
        </section>

        <section class="st-view st-view-idle" id="view-idle" hidden>
            <div class="st-next" id="i-next">
                <div class="st-next-label" id="i-next-label">NEXT KICK-OFF</div>
                <div class="st-next-teams">
                    <div class="st-side-row"><span class="st-tile" id="i-home-tile"></span><span class="st-team" id="i-home-name"></span></div>
                    <span class="st-v">V</span>
                    <div class="st-side-row st-side-row-away"><span class="st-team" id="i-away-name"></span><span class="st-tile" id="i-away-tile"></span></div>
                </div>
                <div class="st-countdown" id="i-countdown">--:--:--</div>
                <div class="st-countdown-units"><span>HOURS</span><span>MINUTES</span><span>SECONDS</span></div>
            </div>
            <div class="st-idle-grid">
                <div class="st-reel" id="i-reel">
                    <div class="st-reel-label" id="i-reel-label">NOW PLAYING · FULL-TIME REEL</div>
                    <video id="i-reel-video" muted playsinline loop></video>
                    <img id="i-reel-art" class="st-reel-art" alt="" hidden>
                    <div class="st-reel-score" id="i-reel-score"></div>
                </div>
                <div class="st-lists">
                    <div class="st-list-head" id="i-later-head">LATER</div>
                    <div class="st-list" id="i-later"></div>
                    <div class="st-list-head" id="i-results-head">FULL TIME</div>
                    <div class="st-list" id="i-results"></div>
                </div>
            </div>
            <div class="st-cta st-cta-idle">
                <div><div class="st-cta-head" id="i-cta-head">EVERY MATCH, LIVE</div><div class="st-cta-line" id="i-cta-line">Scores, the Griot and the full-time reels on matchday.africa</div></div>
                <span class="st-url">matchday.africa</span>
            </div>
        </section>
    </main>

    <div class="st-moment" id="moment" hidden></div>
    <div class="st-flash" id="flash" hidden></div>
    <button type="button" class="st-sound" id="sound" hidden>Tap to turn on sound</button>
</div>
</body>
</html>
