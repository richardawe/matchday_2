#!/usr/bin/env bash
# Remote arguments deliberately expand locally after validation and printf %q quoting.
# shellcheck disable=SC2029
set -Eeuo pipefail

mode=${1:-}
case "$mode" in
    preflight|maintenance|deploy|upload-reel|status|buffer-channels|configure-buffer|plan-posts|reel-check|configure-reel|reel-render|configure-espn|espn-sync|reel-queue|configure-home|radio-check|configure-radio|radio-run|radio-refresh|radio-status|radio-voice|radio-publish|radio-retry) ;;
    *) echo "Usage: $0 preflight|maintenance|deploy|upload-reel|status|buffer-channels|configure-buffer|plan-posts|reel-check|configure-reel|reel-render|configure-espn|espn-sync|reel-queue|configure-home"; exit 2 ;;
esac
for variable in CPANEL_SSH_HOST CPANEL_SSH_USER CPANEL_SSH_KEY CPANEL_SSH_KNOWN_HOSTS CPANEL_APP_ROOT CPANEL_PUBLIC_ROOT; do
    [[ -n "${!variable:-}" ]] || { echo "Missing production secret: $variable"; exit 1; }
done
port=${CPANEL_SSH_PORT:-22}
[[ "$port" =~ ^[0-9]+$ && "$port" -ge 1 && "$port" -le 65535 ]] || { echo "Invalid SSH port."; exit 1; }
[[ "$CPANEL_SSH_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || { echo "Invalid SSH hostname."; exit 1; }
[[ "$CPANEL_SSH_USER" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || { echo "Invalid SSH user."; exit 1; }
[[ "$CPANEL_APP_ROOT" =~ ^/[A-Za-z0-9_./-]+$ && "$CPANEL_APP_ROOT" != / ]] || { echo "Use an absolute application path without spaces."; exit 1; }
[[ "$CPANEL_PUBLIC_ROOT" =~ ^/[A-Za-z0-9_./-]+$ && "$CPANEL_PUBLIC_ROOT" != / && "$CPANEL_PUBLIC_ROOT" != "$CPANEL_APP_ROOT" ]] || { echo "Use a separate absolute public path."; exit 1; }

ssh_dir=$(mktemp -d)
trap 'rm -rf "$ssh_dir"' EXIT
chmod 700 "$ssh_dir"
printf '%s\n' "$CPANEL_SSH_KEY" > "$ssh_dir/key"
printf '%s\n' "$CPANEL_SSH_KNOWN_HOSTS" > "$ssh_dir/known_hosts"
chmod 600 "$ssh_dir/key" "$ssh_dir/known_hosts"
ssh_options=(-i "$ssh_dir/key" -p "$port" -o BatchMode=yes -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$ssh_dir/known_hosts"
    -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3)
target="$CPANEL_SSH_USER@$CPANEL_SSH_HOST"
printf -v root '%q' "$CPANEL_APP_ROOT"
printf -v public '%q' "$CPANEL_PUBLIC_ROOT"

if [[ "$mode" == preflight ]]; then
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root $public" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
[[ -f artisan && -f composer.lock && -f .env && -f vendor/autoload.php ]] || { echo "Incomplete Laravel application root."; exit 1; }
[[ -w . && -w storage && -w bootstrap/cache ]] || { echo "Application directories are not writable."; exit 1; }
[[ -d "$2" && -w "$2" && -f "$2/index.php" ]] || { echo "Public root is unavailable."; exit 1; }
for tool in php composer flock sha256sum rsync mysqldump; do command -v "$tool" >/dev/null; done
php -r '
if (PHP_VERSION_ID < 80200) { fwrite(STDERR, "PHP 8.2+ is required.\n"); exit(1); }
foreach (["ctype", "curl", "dom", "fileinfo", "mbstring", "openssl", "pdo_mysql", "tokenizer", "xml", "gd"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension."\n"); exit(1); }
}
$gd=gd_info();
if (!$gd["FreeType Support"] || !$gd["WebP Support"]) { fwrite(STDERR, "GD requires FreeType and WebP.\n"); exit(1); }
foreach (["proc_open", "exec"] as $function) {
    if (!function_exists($function)) { fwrite(STDERR, "Required PHP function disabled: ".$function."\n"); exit(1); }
}
echo "Server PHP and application-root checks passed.\n";'
composer --version
REMOTE
    # Validate production settings before uploading code; stream the checker without storing it.
    ssh "${ssh_options[@]}" "$target" "cd $root && php /dev/stdin validate $root" \
        < matchday-africa/scripts/deployment-settings.php
    configured_public=$(ssh "${ssh_options[@]}" "$target" "cd $root && php /dev/stdin public $root" \
        < matchday-africa/scripts/deployment-settings.php)
    [[ "$configured_public" == "$CPANEL_PUBLIC_ROOT" ]] || { echo "APP_PUBLIC_PATH and CPANEL_PUBLIC_ROOT must match."; exit 1; }
elif [[ "$mode" == maintenance ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan down --render=errors::503 --retry=60"
elif [[ "$mode" == upload-reel ]]; then
    [[ "${MATCH_ID:-}" =~ ^[0-9]+$ ]] || { echo "MATCH_ID must be numeric."; exit 1; }
    source="matchday-africa/reel/out/$MATCH_ID.mp4"
    [[ -s "$source" ]] || { echo "Rendered reel is missing or empty."; exit 1; }
    printf -v destination '%q' "$CPANEL_PUBLIC_ROOT/generated/reels/$MATCH_ID"
    # The poster goes first: the site treats the MP4's arrival as "ready".
    if [[ -s "matchday-africa/reel/out/$MATCH_ID.jpg" ]]; then
        ssh "${ssh_options[@]}" "$target" \
            "umask 022; mkdir -p $destination && cat > $destination/$MATCH_ID.jpg.upload && mv $destination/$MATCH_ID.jpg.upload $destination/$MATCH_ID.jpg" < "matchday-africa/reel/out/$MATCH_ID.jpg"
    fi
    # Upload one file atomically into the served root, retaining every other reel.
    ssh "${ssh_options[@]}" "$target" \
        "umask 022; mkdir -p $destination && cat > $destination/$MATCH_ID.mp4.upload && mv $destination/$MATCH_ID.mp4.upload $destination/$MATCH_ID.mp4" < "$source"
elif [[ "$mode" == status ]]; then
    # Read-only: release, migrations, social settings (API key shown only as set/unset), cron.
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
echo "Release: $(cat public/deploy-version.txt 2>/dev/null || echo unknown)"
php artisan migrate:status --no-ansi | grep -E "social_posts|x_outbox|match_moments|espn" || true
grep -E '^(APP_URL|APP_TIMEZONE|ESPN_ENABLED|ESPN_SHADOW|REEL_RENDERER|BUFFER_[A-Z_]+)=' .env \
    | sed -E 's/^(BUFFER_API_KEY=).+$/\1<set>/' || true
echo "Scheduler cron:"; crontab -l 2>/dev/null | grep -F artisan || echo "  none found"
php artisan tinker --execute='echo "Posts by channel and status: ".json_encode(DB::table("growth_posts")->selectRaw("channel, status, count(*) n")->groupBy("channel", "status")->get()).PHP_EOL; echo "Planned posts, last 24h:".PHP_EOL; foreach (DB::table("growth_posts")->where("updated_at", ">=", now()->subDay())->where("status", "!=", "draft")->latest("updated_at")->limit(15)->get() as $p) { echo "  {$p->updated_at} {$p->channel} {$p->type} {$p->content_key} {$p->status} ".($p->last_error ?? "").PHP_EOL; } echo "Live posts, last 24h:".PHP_EOL; foreach (DB::table("social_posts")->where("updated_at", ">=", now()->subDay())->latest("updated_at")->limit(15)->get() as $p) { echo "  {$p->updated_at} {$p->channel} {$p->subject} {$p->status} ".($p->error ?? "").PHP_EOL; }' 2>/dev/null || true
REMOTE
elif [[ "$mode" == buffer-channels ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan buffer:publish --channels --no-ansi"
elif [[ "$mode" == plan-posts ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan twitter:engage --no-ansi && php artisan buffer:publish --no-ansi || true"
elif [[ "$mode" == configure-buffer ]]; then
    # Settings travel as JSON on stdin, so the API key never appears in a command line or log.
    # shellcheck disable=SC2016 # the single-quoted program is PHP, not shell
    json=$(php -r '
$set = [];
if (getenv("BUFFER_API_KEY") !== false && getenv("BUFFER_API_KEY") !== "") { $set["BUFFER_API_KEY"] = getenv("BUFFER_API_KEY"); }
foreach (["X", "INSTAGRAM", "TIKTOK"] as $c) {
    $v = trim((string) getenv("INPUT_CHANNEL_".$c));
    if ($v === "") { continue; }
    if (!preg_match("/^[A-Za-z0-9]{6,64}$/", $v)) { fwrite(STDERR, "Invalid $c channel ID.\n"); exit(1); }
    $set["BUFFER_CHANNEL_".$c] = $v;
}
$auto = getenv("INPUT_AUTO");
if ($auto === "true" || $auto === "false") { $set["BUFFER_AUTO"] = $auto; }
$slots = trim((string) getenv("INPUT_SLOTS"));
if ($slots !== "") {
    if (!preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9](,([01][0-9]|2[0-3]):[0-5][0-9]){0,9}$/", $slots)) { fwrite(STDERR, "Slots must look like 08:00,13:00,19:00.\n"); exit(1); }
    $set["BUFFER_SLOTS"] = $slots;
}
if (!$set) { fwrite(STDERR, "Nothing to configure.\n"); exit(1); }
echo json_encode($set);')
    printf '%s' "$json" | ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/set-env.php && php artisan config:cache --no-ansi"
elif [[ "$mode" == reel-check ]]; then
    # Finds a Node 18+ the cron can use, installs the renderer and runs its 2-second test render.
    # Changes nothing in .env; prints the REEL_NODE path to configure.
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
echo "Recent Premier League matches (events, chapters, reel):"
php artisan tinker --execute='foreach (App\Models\FootballMatch::with("homeTeam","awayTeam")->whereHas("league", fn ($q) => $q->where("short_code", "PL"))->whereIn("status", ["FINISHED", "FT"])->latest("match_date")->limit(8)->get() as $m) { echo "  #{$m->id} {$m->match_date} {$m->homeTeam?->name} {$m->home_score}-{$m->away_score} {$m->awayTeam?->name} | events ".$m->events()->whereIn("type", ["goal", "red_card"])->count()." | chapters ".App\Models\MatchMoment::where("match_id", $m->id)->count()." | reel ".json_encode(data_get($m->metadata, "reel")).PHP_EOL; }' 2>&1 | grep -v '^$' || true
configured=$(php scripts/deployment-settings.php node)
best=""; best_major=0
for candidate in "$configured" "$(command -v node || true)" /opt/cpanel/ea-nodejs*/bin/node /opt/alt/alt-nodejs*/root/usr/bin/node /usr/local/bin/node "$HOME"/nodevenv/*/*/bin/node; do
    [[ -n "$candidate" ]] || continue
    path=$(command -v "$candidate" 2>/dev/null || true)
    [[ -n "$path" && -x "$path" ]] || continue
    version=$("$path" --version 2>/dev/null || true)
    echo "Node found: $path $version"
    major=${version#v}; major=${major%%.*}
    if [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 18 && major > best_major )); then best=$path; best_major=$major; fi
done
[[ -n "$best" ]] || { echo "FAIL: no Node 18 or newer on this server. Use REEL_RENDERER=github, or add Node in cPanel (Setup Node.js App)."; exit 1; }
echo "Using $best"
export PATH="$(dirname "$best"):$PATH"
command -v npm >/dev/null || { echo "FAIL: npm is missing next to $best."; exit 1; }
cd reel
npm ci --omit=dev --no-audit --no-fund
"$best" check.mjs
echo "REEL_NODE to configure: $best"
REMOTE
elif [[ "$mode" == configure-reel ]]; then
    # shellcheck disable=SC2016 # the single-quoted program is PHP, not shell
    json=$(php -r '
$renderer = getenv("INPUT_REEL_RENDERER");
if (!in_array($renderer, ["server", "github", "off"], true)) { fwrite(STDERR, "Choose server, github or off.\n"); exit(1); }
$set = ["REEL_RENDERER" => $renderer];
$node = trim((string) getenv("INPUT_REEL_NODE"));
if ($node !== "") {
    if (!preg_match("#^/[A-Za-z0-9_./-]+/node$#", $node)) { fwrite(STDERR, "REEL_NODE must be an absolute path ending in /node.\n"); exit(1); }
    $set["REEL_NODE"] = $node;
}
if ($renderer === "github") {
    if ((string) getenv("REEL_GITHUB_TOKEN") === "") { fwrite(STDERR, "Add the REEL_GITHUB_TOKEN secret to the production environment first.\n"); exit(1); }
    $set["REEL_GITHUB_TOKEN"] = getenv("REEL_GITHUB_TOKEN");
}
echo json_encode($set);')
    printf '%s' "$json" | ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/set-env.php && php artisan config:cache --no-ansi"
elif [[ "$mode" == reel-render ]]; then
    [[ "${INPUT_MATCH_ID:-}" =~ ^[0-9]+$ ]] || { echo "match_id must be numeric."; exit 1; }
    # Rebuild the match's chapters first (older matches may predate them), then render now.
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root $INPUT_MATCH_ID" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
export MATCH_ID="$2"
php artisan tinker --execute='$m = App\Models\FootballMatch::findOrFail((int) getenv("MATCH_ID")); echo "Chapters added: ".app(App\Services\MatchStoryService::class)->sync($m).PHP_EOL;'
php artisan reel:render "$2" --no-ansi
php artisan tinker --execute='echo "Reel: ".json_encode(data_get(App\Models\FootballMatch::find((int) getenv("MATCH_ID"))->metadata, "reel")).PHP_EOL;'
REMOTE
elif [[ "$mode" == configure-espn ]]; then
    # live: ESPN is the primary live source; shadow: only record and log differences; off: football-data only.
    case "${INPUT_ESPN_MODE:-}" in
        live) json='{"ESPN_ENABLED":"true","ESPN_SHADOW":"false"}' ;;
        shadow) json='{"ESPN_ENABLED":"true","ESPN_SHADOW":"true"}' ;;
        off) json='{"ESPN_ENABLED":"false"}' ;;
        *) echo "espn_mode must be live, shadow or off."; exit 1 ;;
    esac
    printf '%s' "$json" | ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/set-env.php && php artisan config:cache --no-ansi"
elif [[ "$mode" == espn-sync ]]; then
    # One fixtures pass now (normally every 6 hours) and today's live/details passes, with unmatched fixtures listed.
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan espn:sync fixtures --no-ansi && php artisan espn:sync live --no-ansi && php artisan espn:sync details --no-ansi"
elif [[ "$mode" == reel-queue ]]; then
    # Backfill: queue reels for finished Premier League matches between two Lagos dates.
    [[ "${INPUT_FROM:-}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ && "${INPUT_TO:-}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "from_date and to_date must look like 2026-10-17."; exit 1; }
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan reel:queue --from=$INPUT_FROM --to=$INPUT_TO --no-ansi"
elif [[ "$mode" == radio-check || "$mode" == configure-radio ]]; then
    radio_mode=${INPUT_RADIO_MODE:-draft}
    [[ "$radio_mode" == draft || "$radio_mode" == auto || "$radio_mode" == off ]] || { echo "Invalid radio mode."; exit 1; }
    printf -v radio_sources '%q' "${INPUT_RADIO_SOURCES:-unchanged}"
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root $mode $radio_mode $radio_sources" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
operation=$2
radio_mode=$3
radio_sources=$4
if [[ "$operation" == configure-radio && "$radio_mode" == off ]]; then
    printf '%s' '{"RADIO_ENABLED":"false","RADIO_AUTO_PUBLISH":"false"}' | php scripts/set-env.php
    php artisan config:cache --no-ansi
    exit 0
fi
best=""
for candidate in "$(php scripts/deployment-settings.php radio-node)" "$(command -v node || true)" /opt/cpanel/ea-nodejs*/bin/node /opt/alt/alt-nodejs*/root/usr/bin/node /usr/local/bin/node "$HOME"/nodevenv/*/*/bin/node; do
    path=$(command -v "$candidate" 2>/dev/null || true)
    [[ -n "$path" && -x "$path" ]] || continue
    if "$path" -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a>22||(a===22&&b>=13)?0:1)' 2>/dev/null; then best=$path; break; fi
done
[[ -n "$best" ]] || { echo "FAIL: radio requires Node 22.13+."; exit 1; }
radio_path="$(dirname "$best"):$PATH"
ffmpeg=""
for candidate in "$(command -v ffmpeg || true)" /usr/bin/ffmpeg /usr/local/bin/ffmpeg "$HOME"/bin/ffmpeg reel/node_modules/@remotion/compositor-linux-*/ffmpeg; do
    [[ -n "$candidate" && -x "$candidate" ]] || continue
    if "$candidate" -hide_banner -encoders 2>/dev/null | grep -F libmp3lame >/dev/null; then ffmpeg=$(realpath "$candidate"); break; fi
done
[[ -n "$ffmpeg" ]] || { echo "FAIL: no FFmpeg with libmp3lame found."; exit 1; }
echo "Radio Node: $best"
echo "Radio FFmpeg: $ffmpeg"
# Keep cPanel's CLI PHP first for set-env/config:cache; /usr/bin/php can be CGI.
(cd radio && PATH="$radio_path" npm ci --omit=dev --no-audit --no-fund && RADIO_FFMPEG="$ffmpeg" "$best" render.mjs --check)
if [[ "$operation" == configure-radio ]]; then
    RADIO_OP_NODE="$best" RADIO_OP_FFMPEG="$ffmpeg" RADIO_OP_MODE="$radio_mode" RADIO_OP_SOURCES="$radio_sources" php -r '$set=["RADIO_ENABLED"=>"true","RADIO_AUTO_PUBLISH"=>getenv("RADIO_OP_MODE")==="auto"?"true":"false","RADIO_NODE"=>getenv("RADIO_OP_NODE"),"RADIO_FFMPEG"=>getenv("RADIO_OP_FFMPEG")]; $sources=getenv("RADIO_OP_SOURCES"); if($sources!=="unchanged") { $names=array_values(array_filter(array_map("trim",explode(",",$sources)))); $allowed=array_column((require "config/news.php")["sources"],"name"); if(array_diff($names,$allowed)) {fwrite(STDERR,"Unknown radio source.\n");exit(1);} $set["RADIO_NEWS_SOURCES"]=implode(",",$names); } echo json_encode($set);' | php scripts/set-env.php
    php artisan config:cache --no-ansi
fi
REMOTE
elif [[ "$mode" == radio-refresh ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan radio:edition --fresh --no-ansi && php artisan radio:work --no-ansi && php scripts/radio-ops.php status"
elif [[ "$mode" == radio-run ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan radio:edition --no-ansi && php artisan radio:work --no-ansi && php scripts/radio-ops.php status"
elif [[ "$mode" == radio-status ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/radio-ops.php status"
elif [[ "$mode" == radio-voice || "$mode" == radio-publish || "$mode" == radio-retry ]]; then
    [[ "${INPUT_BULLETIN_ID:-}" =~ ^[0-9]+$ ]] || { echo "bulletin_id must be numeric."; exit 1; }
    operation=${mode#radio-}
    ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/radio-ops.php $operation $INPUT_BULLETIN_ID"
    if [[ "$operation" == voice || "$operation" == retry ]]; then
        ssh "${ssh_options[@]}" "$target" "cd $root && php artisan radio:work --no-ansi && php scripts/radio-ops.php status $INPUT_BULLETIN_ID"
    fi
elif [[ "$mode" == configure-home ]]; then
    # The reels-first homepage (preview any time with ?home=v2).
    case "${INPUT_HOME_V2:-}" in
        on) json='{"HOME_V2":"true"}' ;;
        off) json='{"HOME_V2":"false"}' ;;
        *) echo "home_v2 must be on or off."; exit 1 ;;
    esac
    printf '%s' "$json" | ssh "${ssh_options[@]}" "$target" "cd $root && php scripts/set-env.php && php artisan config:cache --no-ansi"
else
    [[ "${GITHUB_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || { echo "Missing release commit SHA."; exit 1; }
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root $GITHUB_SHA" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
[[ "$(cat public/deploy-version.txt)" == "$2" ]] || { echo "FTP and SSH do not point at the same uploaded release."; exit 1; }
bash deploy-production.sh
REMOTE
fi
