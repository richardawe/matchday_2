#!/usr/bin/env bash
# Remote arguments deliberately expand locally after validation and printf %q quoting.
# shellcheck disable=SC2029
set -Eeuo pipefail

mode=${1:-}
[[ "$mode" == preflight || "$mode" == maintenance || "$mode" == deploy ]] || { echo "Usage: $0 preflight|maintenance|deploy"; exit 2; }
for variable in CPANEL_SSH_HOST CPANEL_SSH_USER CPANEL_SSH_KEY CPANEL_SSH_KNOWN_HOSTS CPANEL_APP_ROOT; do
    [[ -n "${!variable:-}" ]] || { echo "Missing production secret: $variable"; exit 1; }
done
port=${CPANEL_SSH_PORT:-22}
[[ "$port" =~ ^[0-9]+$ && "$port" -ge 1 && "$port" -le 65535 ]] || { echo "Invalid SSH port."; exit 1; }
[[ "$CPANEL_SSH_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || { echo "Invalid SSH hostname."; exit 1; }
[[ "$CPANEL_SSH_USER" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]] || { echo "Invalid SSH user."; exit 1; }
[[ "$CPANEL_APP_ROOT" =~ ^/[A-Za-z0-9_./-]+$ && "$CPANEL_APP_ROOT" != / ]] || { echo "Use an absolute application path without spaces."; exit 1; }

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

if [[ "$mode" == preflight ]]; then
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
[[ -f artisan && -f composer.lock && -f .env && -f vendor/autoload.php ]] || { echo "Incomplete Laravel application root."; exit 1; }
[[ -w . && -w storage && -w bootstrap/cache ]] || { echo "Application directories are not writable."; exit 1; }
for tool in php composer flock sha256sum; do command -v "$tool" >/dev/null; done
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
elif [[ "$mode" == maintenance ]]; then
    ssh "${ssh_options[@]}" "$target" "cd $root && php artisan down --render=errors::503 --retry=60"
else
    [[ "${GITHUB_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || { echo "Missing release commit SHA."; exit 1; }
    ssh "${ssh_options[@]}" "$target" "bash -s -- $root $GITHUB_SHA" <<'REMOTE'
set -Eeuo pipefail
cd "$1"
[[ "$(cat public/deploy-version.txt)" == "$2" ]] || { echo "FTP and SSH do not point at the same uploaded release."; exit 1; }
bash deploy-production.sh
REMOTE
fi
