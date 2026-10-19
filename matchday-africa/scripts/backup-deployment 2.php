<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
umask(0077);
$directory = storage_path('app/deploy-backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)));
if (!mkdir($directory, 0700, true) || !copy(base_path('.env'), $directory.'/.env')) {
    throw new RuntimeException('Could not create a private deployment backup.');
}
$connection = config('database.default');
$database = config('database.connections.'.$connection);
if ($connection === 'sqlite') {
    // VACUUM INTO includes committed WAL contents, unlike copying the database file.
    Illuminate\Support\Facades\DB::statement('VACUUM INTO '.Illuminate\Support\Facades\DB::getPdo()->quote($directory.'/database.sqlite'));
} elseif ($connection === 'mysql' || $connection === 'mariadb') {
    $arguments = ['mysqldump', '--single-transaction', '--skip-lock-tables', '--no-tablespaces',
        '--host='.$database['host'], '--port='.($database['port'] ?? 3306),
        '--user='.$database['username'], '--result-file='.$directory.'/database.sql'];
    if (!empty($database['unix_socket'])) {
        $arguments[] = '--socket='.$database['unix_socket'];
    }
    $arguments[] = $database['database'];
    $process = new Symfony\Component\Process\Process($arguments, null, ['MYSQL_PWD' => $database['password']]);
    $process->setTimeout(300);
    // Never include the subprocess command or connection settings in a thrown exception.
    if ($process->run() !== 0 || !is_file($directory.'/database.sql') || filesize($directory.'/database.sql') === 0) {
        throw new RuntimeException('Database backup failed; deployment stopped before migrations.');
    }
} else {
    throw new RuntimeException('Configure a deployment backup for this database driver.');
}
echo 'Private database and environment backup saved: '.$directory.PHP_EOL;
