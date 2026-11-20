<?php
declare(strict_types=1);

/** Read a setting from the real environment first, then from the .env file. */
function env(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $local = ROOT . '/config/config.local.php';          // optional: copy config/config.example.php to this name
        if (is_readable($local)) {
            $arr = require $local;
            $file = is_array($arr) ? array_map('strval', $arr) : [];
        }
        $path = ROOT . '/.env';                              // optional too (see .env.example)
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
