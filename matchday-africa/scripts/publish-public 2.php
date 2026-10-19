<?php

// Adapt only the front controller when cPanel serves a separate public_html.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = realpath($argv[1] ?? '');
$public = realpath($argv[2] ?? '');
if (!$root || !$public || $root === $public || !is_file($root.'/artisan')) {
    fwrite(STDERR, "Invalid application or public root.\n");
    exit(1);
}
if ($public === realpath($root.'/public')) {
    exit(0);
}
$rootLiteral = var_export($root, true);
$publicLiteral = var_export($public, true);
$source = <<<PHP
<?php
define('LARAVEL_START', microtime(true));
\$root = $rootLiteral;
if (file_exists(\$maintenance = \$root.'/storage/framework/maintenance.php')) {
    require \$maintenance;
}
require \$root.'/vendor/autoload.php';
\$app = require \$root.'/bootstrap/app.php';
\$app->usePublicPath($publicLiteral);
\$app->handleRequest(Illuminate\Http\Request::capture());
PHP;
$temporary = $public.'/index.php.deploy';
if (file_put_contents($temporary, $source) === false || !chmod($temporary, 0644) || !rename($temporary, $public.'/index.php')) {
    fwrite(STDERR, "Could not publish the public front controller.\n");
    exit(1);
}
