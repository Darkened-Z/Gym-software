<?php
/**
 * Per-gym PWA manifest. Replaces the static manifest.webmanifest so the
 * installed-app name and icon come from each gym's own settings (gym_name,
 * logo_url) instead of being hardcoded per branch. Safe: falls back to a
 * neutral default logo present in the install when no logo is set.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/models/Setting.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$name = 'Gym';
$logo = '';
try {
    $s = (new Setting((new Database())->getConnection()))->getPublicMap();
    $name = trim((string)($s['gym_name'] ?? '')) ?: 'Gym';
    $logo = trim((string)($s['logo_url'] ?? ''));
} catch (Throwable $e) {
    // fall through to defaults
}

// Icon: the uploaded logo if set, else whichever default logo this install ships.
$icon = $logo;
if ($icon === '') {
    foreach (['assets/images/bhatti-logo.png', 'assets/icons/bhatti-icon-192.png', 'assets/icons/gym-icon-192.png'] as $cand) {
        if (file_exists(__DIR__ . '/' . $cand)) { $icon = $cand; break; }
    }
    if ($icon === '') { $icon = 'assets/images/bhatti-logo.png'; }
}

$ext = strtolower(pathinfo(parse_url($icon, PHP_URL_PATH) ?: $icon, PATHINFO_EXTENSION));
$mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
$mime = $mimes[$ext] ?? 'image/png';

echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 24),
    'description' => $name . ' — membership, attendance & payments',
    'start_url' => './',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#0d0d0d',
    'theme_color' => '#0d0d0d',
    'icons' => [
        ['src' => $icon, 'sizes' => '192x192', 'type' => $mime, 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => $mime, 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => $mime, 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
