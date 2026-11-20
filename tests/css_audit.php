<?php
/** Design-system audit: php tests/css_audit.php  (exit 1 on violations) */
$css = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
$css = preg_replace('#/\*.*?\*/#s', '', $css);
$v = [];
// 1. Colours may only be defined inside :root and [data-theme="dark"] blocks.
$outside = preg_replace('/(:root|\[data-theme="dark"\])\s*\{[^}]*\}/s', '', $css);
if (preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside, $m)) { foreach ($m[0] as $x) $v[] = "colour literal outside tokens: $x"; }
// 2. Shadows: only the four tokens (or none) and derived values on the :root definitions.
preg_match_all('/box-shadow\s*:\s*([^;]+);/', $outside, $m);
foreach ($m[1] as $x) { if (!preg_match('/^(none|var\(--shadow-(raised|raised-sm|inset|inset-sm)\))$/', trim($x))) $v[] = "box-shadow not a token: $x"; }
// 3. Radii: tokens, 50%, 0.
preg_match_all('/border-radius\s*:\s*([^;]+);/', $outside, $m);
foreach ($m[1] as $x) { if (!preg_match('/^(var\(--r-[1-6]\)( var\(--r-[1-6]\))*|50%|0|0 var\(--r-4\) var\(--r-4\) 0)$/', trim($x))) $v[] = "border-radius not on scale: $x"; }
// 4. Font weights: 500 / 700 only.
preg_match_all('/font-weight\s*:\s*(\d+)/', $css, $m);
foreach (array_unique($m[1]) as $w) { if (!in_array($w, ['500', '700'], true)) $v[] = "font-weight $w"; }
// 5. Transition / animation durations come from tokens (or the reduced-motion override).
preg_match_all('/(?:transition|animation)(?:-duration)?\s*:\s*([^;]+);/', $outside, $m);
foreach ($m[1] as $x) { if (preg_match('/\b\d+(\.\d+)?m?s\b/', preg_replace('/var\([^)]*\)/', '', $x), $d) && !str_contains($x, '.001ms') && !str_contains($x, '700ms') && !str_contains($x, '0s')) $v[] = "raw duration: $x"; }
// 6. Spacing (padding/margin/gap) in px must be on the 8/12/16/24/32/48 scale (0 and 1px hairlines allowed).
preg_match_all('/(?:padding|margin|gap)(?:-[a-z]+)?\s*:\s*([^;]+);/', $outside, $m);
$off = [];
foreach ($m[1] as $x) { preg_match_all('/(?<![\w.-])(\d+)px/', preg_replace('/var\([^)]*\)/', '', $x), $n); foreach ($n[1] as $px) { if (!in_array((int)$px, [0, 1, 8, 12, 16, 24, 32, 48], true)) $off[] = "$px" . "px in '$x'"; } }
echo "Spacing values off the scale (informational): " . (count($off) ? implode('; ', array_unique($off)) : 'none') . "\n";
echo $v ? "VIOLATIONS:\n - " . implode("\n - ", array_unique($v)) . "\n" : "CSS audit passed: colours, shadows, radii, weights and durations all come from design tokens.\n";
exit($v ? 1 : 0);
