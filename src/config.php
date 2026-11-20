<?php
declare(strict_types=1);

/** Read a setting from the real environment first, then from the .env file. */
function env(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = ROOT . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }
    return $file[$key] ?? $default;
}
