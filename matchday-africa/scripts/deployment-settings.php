<?php

// CLI-only production deployment checks. Never print credentials or load .env as shell code.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = $argv[2] ?? dirname(__DIR__);
require $root.'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->load();
$value = static fn (string $key, string $default = ''): string => (string) ($_ENV[$key] ?? $default);
$mode = $argv[1] ?? 'validate';

if ($mode === 'radio') {
    // The Griot's live voice and writer run on the radio's Node runtime too.
    $on = static fn (string $key): bool => in_array(strtolower($value($key, 'false')), ['true', '1'], true);
    echo $on('RADIO_ENABLED') || $on('GRIOT_VOICE') || $on('GRIOT_WRITER') ? 'on' : 'off';
    exit(0);
}
if ($mode === 'radio-node') {
    echo $value('RADIO_NODE', $value('REEL_NODE', 'node'));
    exit(0);
}

if ($mode === 'renderer') {
    echo $value('REEL_RENDERER', 'off');
    exit(0);
}
if ($mode === 'node') {
    echo $value('REEL_NODE', 'node');
    exit(0);
}
if ($mode === 'public') {
    $path = $value('APP_PUBLIC_PATH', $root.'/public');
    if (!str_starts_with($path, '/') || !is_dir($path) || realpath($path) === realpath($root)) {
        fwrite(STDERR, "APP_PUBLIC_PATH must be an existing public directory outside the private app root.\n");
        exit(1);
    }
    $resolved = realpath($path);
    $private = realpath($root);
    if (str_starts_with($resolved, $private.'/') && $resolved !== $private.'/public') {
        fwrite(STDERR, "The served directory cannot expose private application files.\n");
        exit(1);
    }
    echo $resolved;
    exit(0);
}

$errors = [];
if ($value('APP_ENV') !== 'production') {
    $errors[] = 'APP_ENV must be production.';
}
if (!in_array(strtolower($value('APP_DEBUG')), ['false', '0'], true)) {
    $errors[] = 'APP_DEBUG must be false.';
}
if ($value('APP_KEY') === '') {
    $errors[] = 'APP_KEY must already be configured; deployments never rotate it.';
}
foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    if (preg_match('/REPLACE|CPANELUSER/i', $value($key))) {
        $errors[] = $key.' still contains a template placeholder.';
    }
}
if (!in_array($value('REEL_RENDERER', 'off'), ['off', 'server', 'github'], true)) {
    $errors[] = 'REEL_RENDERER must be off, server, or github.';
}
foreach ($errors as $error) {
    fwrite(STDERR, $error.PHP_EOL);
}
exit($errors ? 1 : 0);
