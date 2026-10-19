<?php

/*
 * Sets keys in the production .env from a JSON object on stdin ({"KEY": "value", ...}), for the
 * Server operations workflow. Values arrive on stdin so secrets never appear in a command line or
 * log. The current .env is backed up first (.env.backup-YYYYmmdd-His, owner-only) and replaced
 * atomically; other lines are kept as they are. Prints the keys it changed, never the values.
 *
 *   php scripts/set-env.php < values.json
 */

$values = json_decode(stream_get_contents(STDIN), true);
if (!is_array($values) || $values === []) {
    fwrite(STDERR, "Expected a JSON object of settings on stdin.\n");
    exit(1);
}
foreach ($values as $key => $value) {
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) || !is_scalar($value) || preg_match('/[\r\n\0]/', (string) $value)) {
        fwrite(STDERR, "Refusing setting {$key}: keys are UPPER_CASE and values a single line.\n");
        exit(1);
    }
}

$file = getcwd().'/.env';
if (!is_file($file) || !is_writable($file)) {
    fwrite(STDERR, ".env is missing or not writable in ".getcwd()."\n");
    exit(1);
}
$original = file_get_contents($file);
$backup = $file.'.backup-'.date('Ymd-His');
file_put_contents($backup, $original);
chmod($backup, 0600);

$lines = preg_split('/\r?\n/', rtrim($original, "\r\n"));
$changed = [];
foreach ($values as $key => $value) {
    $value = (string) $value;
    $quoted = preg_match('/^[A-Za-z0-9_.:\/@,+-]*$/', $value) ? $value : '"'.addcslashes($value, '"\\$').'"';
    $line = "{$key}={$quoted}";
    $found = false;
    foreach ($lines as $i => $existing) {
        if (preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/', $existing)) {
            if ($existing !== $line) {
                $changed[] = $key;
            }
            $lines[$i] = $found ? null : $line;
            $found = true;
        }
    }
    if (!$found) {
        $lines[] = $line;
        $changed[] = $key;
    }
}

$tmp = $file.'.tmp-'.getmypid();
file_put_contents($tmp, implode("\n", array_filter($lines, fn ($l) => $l !== null))."\n");
chmod($tmp, fileperms($file) & 0777);
rename($tmp, $file);

echo $changed ? 'Updated .env: '.implode(', ', array_unique($changed)).' (backup '.basename($backup).")\n" : "No .env changes needed.\n";
