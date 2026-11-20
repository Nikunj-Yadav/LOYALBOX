<?php
declare(strict_types=1);

/** Escape for HTML output. Use on every value that came from users or the database. */
function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function redirect(string $to): never { header('Location: ' . $to); exit; }

function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type' => $type, 'message' => $message]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/** Trimmed string from $_POST, cut to $max characters. */
function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
/** Integer from $_POST within [$min, $max], or null if missing/invalid. */
function post_int(string $key, int $min = 0, int $max = 2000000000): ?int
{
    $v = $_POST[$key] ?? null;
    if (!is_string($v)) { return null; }
    $i = filter_var(trim($v), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $i === false ? null : $i;
}
function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function fmt_num(int|string|null $n): string { return number_format((int)$n); }
function fmt_dt(?string $ts): string { return $ts ? date('d M Y, H:i', strtotime($ts)) : '—'; }

/** Validate a new password. Returns an error message or null. */
function password_error(string $pw): ?string
{
    if (mb_strlen($pw) < 10) { return 'Use at least 10 characters.'; }
    if (strlen($pw) > 72) { return 'Use at most 72 characters.'; }
    return null;
}

/** Inline SVG icons (24x24 stroke icons). Decorative, so hidden from screen readers. */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'scan' => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v9H5v-9"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.5A6.5 6.5 0 0 1 21.5 20"/>',
        'logout' => '<path d="M9 21H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M12 8v5M12 16h.01"/>',
        'file' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'play' => '<path d="M7 4l13 8-13 8z"/>',
        'alert' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'star' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'undo' => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    ];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}
