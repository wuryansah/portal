<?php
// Minimal .env loader: KEY=VALUE lines into the environment.
// Existing environment variables always win; comments/blank lines are skipped.
static $envLoaded = false;
if ($envLoaded) return;
$envLoaded = true;

$envFile = __DIR__ . '/.env';
if (!is_file($envFile)) return;

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $eq = strpos($line, '=');
    if ($eq === false) continue;
    $key = trim(substr($line, 0, $eq));
    $value = trim(substr($line, $eq + 1));
    if ($key === '' || getenv($key) !== false || array_key_exists($key, $_ENV)) continue;
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}