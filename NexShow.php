<?php
/**
 * NexShoW — full-screen image slider in a single PHP file.
 *
 * Everything — frames, slider settings, per-frame defaults and the admin password —
 * lives in the NexShoW_db database (see db-config.php) and is managed from admin.php.
 * The arrays below are only an emergency fallback for when the database is completely
 * unreachable; normally every value in them is replaced by one read from the database.
 */

require __DIR__ . '/db-config.php';

// ---------- Global slider behaviour (normally read from site_settings) ----------
$slider = xs_db_slider_defaults() ?? [
    'autoplay' => true, 'duration' => 5000, 'transition' => 'fade', 'transitionMs' => 1200,
    'pauseOnHover' => true, 'swipeThreshold' => 50, 'mobileBreakpoint' => 768, 'ariaLabel' => 'Slideshow',
    'orientation' => 'landscape',
];

// ---------- Per-slide defaults (normally read from the frames table's own column defaults) ----------
$slideDefaults = xs_db_frame_defaults() ?? [
    'textAlign' => 'left', 'verticalAlign' => 'center', 'offsetX' => '0px', 'offsetY' => '0px',
    'overlay' => true, 'overlayStrength' => 0.55, 'overlayDirection' => 'auto', 'focalPoint' => '50% 50%',
    'mobileFocalPoint' => null, 'textColor' => '#ffffff', 'fontFamily' => null, 'titleSize' => null,
    'titleWeight' => null, 'kenBurns' => true, 'openInNewTab' => false,
];

// ---------- Slides ----------
// Shown only if the database has no frames yet (see $dbFrames below). Add frames from
// admin.php instead of editing this — it exists purely as an empty-database fallback.
$slides = [];

$pageTitle = 'NexShoW';

// =====================================================================
//  Nothing below needs editing for content changes.
// =====================================================================

// admin.php includes this file only to read the schema-derived defaults above — every value
// below this point is scoped to one company, which admin.php (and the device gate further
// down) resolve for themselves from a trusted session, not from here.
if (defined('NEXSHOW_CONFIG_ONLY')) return;

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Normalizes an image field to a URL, or null if the slide has none. */
function img_url($src)
{
    return $src ? $src : null;
}

/** Merges one company's real settings + frames onto the schema-default fallbacks.
 *  Returns [$pageTitle, $slider, $slides, $dbSettings]. */
function xs_load_company($companyId, $pageTitle, $slider, $slides)
{
    $dbSettings = xs_db_get_settings($companyId);
    if ($dbSettings) {
        if (!empty($dbSettings['settings']['pageTitle'])) $pageTitle = $dbSettings['settings']['pageTitle'];
        $slider = array_merge($slider, array_intersect_key($dbSettings['settings'], $slider));
    }
    $dbFrames = xs_db_list_frames($companyId);
    if ($dbFrames) $slides = $dbFrames;
    return [$pageTitle, $slider, $slides, $dbSettings];
}

// =====================================================================
//  Device menu: the login + settings screen meant for the physical
//  device/TV running this page. It's what plain NexShow.php opens with —
//  the live show itself moved to NexShow.php?show=1 (and ?preview=1, used
//  by admin.php's preview pane, always bypasses this gate too). Logs in
//  with the same `users` table as admin.php (its own session though, so
//  being signed in to one doesn't sign you in to the other). Only touches
//  the live show through $slider['orientation'] (see body.fs-portrait
//  below) and site_settings.support_info — everything else (frames,
//  slider timing, etc.) is still managed from admin.php.
// =====================================================================
if (!isset($_GET['show']) && !isset($_GET['preview'])) {
    session_name('nexshow_device');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');

    function xs_device_text($v, $max = 2000)
    {
        $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', trim((string) $v));
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    }

    function xs_device_redirect($query = '', $msg = '', $type = 'ok')
    {
        if ($msg !== '') $_SESSION['device_flash'] = [$type, $msg];
        header('Location: NexShow.php' . ($query !== '' ? '?' . $query : ''));
        exit;
    }

    $dbOk = xs_db() !== null;
    // No separate device account — this screen logs in with the same username+password as
    // admin.php (the `users` table). Whoever logs in here sees their own company's menu/show.
    $deviceAuthed = !empty($_SESSION['xs_device_auth']) && !empty($_SESSION['xs_device_company_id']) && !empty($_SESSION['xs_device_user_id']);
    // 'user' role accounts expire 1 year after creation; admins never do — checked at login
    // and again here on every request, so a session left open since before the expiry date
    // is signed out the moment it crosses it (matters most for ?show=1, which can sit open
    // on a screen for a long time).
    if ($deviceAuthed && !empty($_SESSION['xs_device_expires_at']) && strtotime($_SESSION['xs_device_expires_at']) <= time()) {
        xs_db_expire_user((int) $_SESSION['xs_device_user_id']);
        $_SESSION = [];
        $_SESSION['device_flash'] = ['error', 'That account has expired. Contact your admin.'];
        session_regenerate_id(true);
        $deviceAuthed = false;
    }
    // Single device session per user (logging in elsewhere overwrites device_session_token)
    // and deactivation (an admin can turn a user off mid-session from admin.php's Users panel)
    // both need a real read, not something session-cached like expiry above — so one fresh
    // lookup here covers both, signing this device out the next time it's seen here.
    if ($deviceAuthed) {
        $currentDeviceUser = xs_db_find_user_by_id((int) $_SESSION['xs_device_user_id']);
        if ($currentDeviceUser !== null && $currentDeviceUser['status'] !== 'active') {
            $_SESSION = [];
            $_SESSION['device_flash'] = ['error', 'This account has been deactivated. Contact your admin.'];
            session_regenerate_id(true);
            $deviceAuthed = false;
        } elseif ($currentDeviceUser !== null && (string) $currentDeviceUser['device_session_token'] !== (string) ($_SESSION['xs_device_token'] ?? '')) {
            $_SESSION = [];
            $_SESSION['device_flash'] = ['error', 'Signed out — this account signed in on another device.'];
            session_regenerate_id(true);
            $deviceAuthed = false;
        }
    }
    $deviceCompanyId = $deviceAuthed ? (int) $_SESSION['xs_device_company_id'] : null;
    if (empty($_SESSION['device_csrf'])) $_SESSION['device_csrf'] = bin2hex(random_bytes(16));
    $deviceCsrf = $_SESSION['device_csrf'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($deviceCsrf, (string) ($_POST['csrf'] ?? ''))) {
            xs_device_redirect('', 'Your session expired. Please try again.', 'error');
        }
        $action = (string) ($_POST['action'] ?? '');

        try {
            if (!$deviceAuthed) {
                if ($action === 'device_login') {
                    $u = xs_device_text($_POST['username'] ?? '', 80);
                    $user = $u !== '' ? xs_db_find_user_by_username($u) : null;
                    // Password checked before status/expiry, so only someone who actually knows
                    // the right password ever sees *why* they can't in — not just anyone guessing
                    // usernames.
                    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                        // This screen is for 'user' accounts only — an 'admin' account (the
                        // slider admin login) must use admin.php instead, never this one.
                        if ($user['role'] !== 'user') {
                            sleep(1);
                            xs_device_redirect('', 'This is an admin account — sign in from the admin panel instead.', 'error');
                        }
                        if ($user['status'] !== 'active' || $user['payment_status'] === 'pending') {
                            sleep(1);
                            xs_device_redirect('', 'This account has been deactivated. Contact your admin.', 'error');
                        }
                        if (xs_user_is_expired($user)) {
                            xs_db_expire_user((int) $user['id']);
                            xs_device_redirect('', 'That account has expired. Contact your admin.', 'error');
                        }
                        // A fresh token here, saved to this user's row, is what signs out
                        // whichever other device was already logged in as this same user.
                        $deviceToken = bin2hex(random_bytes(20));
                        xs_db_set_device_session_token((int) $user['id'], $deviceToken);
                        session_regenerate_id(true);
                        $_SESSION['xs_device_auth'] = true;
                        $_SESSION['xs_device_user_id'] = (int) $user['id'];
                        $_SESSION['xs_device_company_id'] = (int) $user['company_id'];
                        $_SESSION['xs_device_expires_at'] = $user['expires_at'];
                        $_SESSION['xs_device_token'] = $deviceToken;
                        xs_device_redirect();
                    }
                    sleep(1);
                    xs_device_redirect('', 'Wrong username or password.', 'error');
                }
                xs_device_redirect();
            }

            switch ($action) {
                case 'device_logout':
                    $_SESSION = [];
                    session_regenerate_id(true);
                    xs_device_redirect();
                    break;

                case 'save_orientation':
                    if (!$dbOk) xs_device_redirect('screen=orientation', 'Could not reach the database.', 'error');
                    $o = ($_POST['orientation'] ?? '') === 'portrait' ? 'portrait' : 'landscape';
                    xs_db_save_settings($deviceCompanyId, ['orientation' => $o]);
                    xs_device_redirect('screen=orientation', 'Orientation saved.');
                    break;

            }
            xs_device_redirect();
        } catch (RuntimeException $ex) {
            xs_device_redirect('', $ex->getMessage(), 'error');
        }
    }

    $flash = $_SESSION['device_flash'] ?? null;
    unset($_SESSION['device_flash']);

    $screen = !$deviceAuthed ? 'login' : (string) ($_GET['screen'] ?? 'menu');
    if (!in_array($screen, ['login', 'menu', 'orientation', 'info'], true)) $screen = 'menu';
    $orientation = 'landscape';
    if ($deviceAuthed) {
        [$pageTitle, , , $companyDbSettings] = xs_load_company($deviceCompanyId, $pageTitle, $slider, $slides);
        $orientation = $companyDbSettings['settings']['orientation'] ?? 'landscape';
    }

    $titles = ['login' => 'Sign in', 'menu' => 'Menu', 'orientation' => 'Orientation', 'info' => 'Product information'];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titles[$screen]) ?> &middot; <?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --grad-blue: #19a7ff;
  --grad-purple: #9b2fe0;
  --neon-pink: #ff2d8f;
  --text: #f3f1fb;
  --muted: rgba(243, 241, 251, 0.68);
  --card: rgba(255, 255, 255, 0.07);
  --card-line: rgba(255, 255, 255, 0.14);
  --card-hover: rgba(255, 255, 255, 0.13);
  --font-ui: 'Instrument Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}
* , *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body {
  min-height: 100vh; min-height: 100dvh;
  font-family: var(--font-ui); color: var(--text);
  background:
    radial-gradient(60% 50% at 80% 12%, rgba(155, 47, 224, 0.38), transparent 60%),
    radial-gradient(55% 45% at 10% 90%, rgba(25, 167, 255, 0.28), transparent 60%),
    linear-gradient(165deg, #0a0d1c 0%, #0c1130 55%, #140a28 100%);
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; }
button, input { font: inherit; color: inherit; }

.flash {
  max-width: 420px; margin: 0 auto 1.25rem; padding: 0.75rem 1rem;
  border-radius: 10px; border: 1px solid; font-size: 0.9375rem; text-align: center;
}
.flash--ok { color: #a9f0b8; background: rgba(148, 207, 159, 0.12); border-color: rgba(148, 207, 159, 0.3); }
.flash--error { color: #ffb3a3; background: rgba(232, 128, 111, 0.14); border-color: rgba(232, 128, 111, 0.35); }

/* ---------- Auth (setup / login) ---------- */
.auth { min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; }
.auth__card { width: min(380px, 100%); text-align: center; }
.auth__logo { width: 160px; height: 160px; margin: 0 auto 1.25rem; display: block; filter: drop-shadow(0 8px 30px rgba(155, 47, 224, 0.45)); }
.auth__card h1 { font-size: 1.6rem; font-weight: 600; margin: 0 0 0.35rem; }
.auth__card > p { margin: 0 0 1.5rem; color: var(--muted); }
.field { display: grid; gap: 0.4rem; margin-bottom: 1rem; text-align: left; }
.field span { font-size: 0.8125rem; font-weight: 500; color: var(--muted); }
.field input {
  min-height: 46px; padding: 0.6rem 0.85rem;
  background: rgba(255, 255, 255, 0.06); border: 1px solid var(--card-line); border-radius: 10px;
  color: var(--text);
}
.field input:focus { outline: 2px solid var(--grad-blue); outline-offset: 1px; }
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
  width: 100%; min-height: 48px; margin-top: 0.5rem; padding: 0.6rem 1.25rem;
  background: linear-gradient(90deg, var(--grad-blue), var(--grad-purple));
  border: 0; border-radius: 999px; color: #fff; font-weight: 600; font-size: 0.9375rem;
  cursor: pointer; text-decoration: none;
}
.btn:hover { filter: brightness(1.08); }
.btn:focus { outline: 2px solid #fff; outline-offset: 2px; }

/* ---------- Menu ---------- */
.menu-top {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px clamp(16px, 4vw, 48px) 0;
}
.menu-top__brand { font-weight: 600; font-size: 1.6rem; }
.menu-top__brand::first-letter { color: var(--neon-pink); font-size: 1.5em; }
.menu-top__right { display: flex; align-items: center; gap: 1rem; color: var(--muted); font-size: 0.875rem; }
.menu-top form { margin: 0; }
.menu-logout {
  display: inline-flex; align-items: center; gap: 0.4rem;
  min-height: 34px; padding: 0.4rem 1rem;
  background: var(--card); border: 1px solid var(--card-line); border-radius: 999px;
  color: var(--text); font: inherit; font-size: 0.8125rem; font-weight: 500; cursor: pointer;
  transition: background-color .2s, border-color .2s;
}
.menu-logout:hover, .menu-logout:focus { background: var(--card-hover); border-color: var(--neon-pink); color: var(--neon-pink); outline: none; }
.menu-logout:focus { box-shadow: 0 0 0 3px var(--neon-pink); }

.menu-wrap { min-height: 100%; display: flex; flex-direction: column; }
.menu-body { flex: 1; display: flex; flex-direction: column; align-items: center; padding: 48px 16px 48px; }
.menu-body h1 { font-size: clamp(1.5rem, 3vw, 2rem); font-weight: 600; margin: 0; letter-spacing: 0.01em; }
.tiles-wrap { flex: 1; width: 100%; display: flex; align-items: center; justify-content: center; }

.tiles { display: flex; flex-wrap: wrap; gap: 1.25rem; justify-content: center; max-width: 980px; }
.tile {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.9rem;
  width: 190px; min-height: 230px; padding: 2.5rem 1rem;
  background: var(--card); border: 1px solid var(--card-line); border-radius: 18px;
  color: var(--text); text-decoration: none; text-align: center;
  transition: background-color .2s, border-color .2s, transform .2s;
}
.tile:hover, .tile:focus { background: var(--card-hover); border-color: var(--neon-pink); transform: translateY(-2px); outline: none; }
.tile:focus { box-shadow: 0 0 0 3px var(--neon-pink); }
.tile__icon {
  display: grid; place-items: center; width: 56px; height: 56px; border-radius: 50%;
  background: linear-gradient(135deg, var(--grad-blue), var(--grad-purple));
}
.tile__icon svg { width: 28px; height: 28px; display: block; }
.sub-back svg { width: 18px; height: 18px; display: block; flex: none; }
.orient-opt__card svg { width: 30px; height: 30px; display: block; }
.tile__label { font-size: 0.9375rem; font-weight: 500; }

/* ---------- Sub-screens (orientation / info) ---------- */
.sub-wrap { min-height: 100%; padding: 20px clamp(16px, 4vw, 48px) 48px; }
.sub-back { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--muted); text-decoration: none; font-size: 0.875rem; margin-bottom: 1.5rem; border-radius: 999px; }
.sub-back:hover { color: var(--text); }
.sub-back:focus { color: var(--text); box-shadow: 0 0 0 3px var(--neon-pink); outline: none; }
.sub-wrap h1 { font-size: clamp(1.4rem, 3vw, 1.9rem); font-weight: 600; margin: 0 0 1.75rem; }
.sub-panel { max-width: 560px; }

.orient-options { display: flex; gap: 1rem; margin-bottom: 1.75rem; }
.orient-opt { flex: 1; }
.orient-opt input { position: absolute; opacity: 0; pointer-events: none; }
.orient-opt__card {
  display: flex; flex-direction: column; align-items: center; gap: 0.75rem;
  padding: 1.5rem 1rem; background: var(--card); border: 1px solid var(--card-line); border-radius: 16px; cursor: pointer;
}
.orient-opt input:checked + .orient-opt__card { border-color: var(--grad-blue); background: var(--card-hover); box-shadow: 0 0 0 1px var(--grad-blue); }
.orient-opt input:focus + .orient-opt__card { box-shadow: 0 0 0 3px var(--neon-pink); }
.orient-opt__card svg { width: 32px; height: 32px; }
.hint { color: var(--muted); font-size: 0.875rem; margin: 0 0 1.5rem; }

.info-block { margin-bottom: 1.75rem; }
.info-block h2 { font-size: 0.9375rem; font-weight: 600; margin: 0 0 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.info-card { background: var(--card); border: 1px solid var(--card-line); border-radius: 14px; padding: 1rem 1.25rem; }
.info-block .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 0; }
.info-block .field { margin-bottom: 0; }

.help-card { background: var(--card); border: 1px solid var(--card-line); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.75rem; }
.help-card__head { display: flex; align-items: center; gap: 0.9rem; margin-bottom: 1.1rem; }
.help-card__icon {
  display: grid; place-items: center; width: 48px; height: 48px; border-radius: 14px; flex: none;
  background: linear-gradient(135deg, var(--grad-blue), var(--grad-purple));
}
.help-card__icon svg { width: 24px; height: 24px; color: #fff; }
.help-card__title { margin: 0; font-size: 1.0625rem; font-weight: 600; }
.help-card__sub { margin: 0.15rem 0 0; font-size: 0.8125rem; color: var(--muted); }
.help-row {
  display: flex; align-items: center; gap: 0.9rem; padding: 0.85rem 0.4rem;
  text-decoration: none; color: var(--text); border-top: 1px solid var(--card-line);
  border-radius: 10px; transition: background-color .15s;
}
.help-row:hover, .help-row:focus { background: rgba(255, 255, 255, 0.05); outline: none; }
.help-row:focus { box-shadow: 0 0 0 3px var(--neon-pink); }
.help-row__icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; flex: none; }
.help-row__icon svg { width: 19px; height: 19px; }
.help-row__icon--whatsapp { background: rgba(37, 211, 102, 0.16); color: #25d366; }
.help-row__icon--mail { background: rgba(25, 167, 255, 0.16); color: var(--grad-blue); }
.help-row__icon--ticket { background: rgba(155, 47, 224, 0.18); color: var(--grad-purple); }
.help-row__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.15rem; }
.help-row__text strong { font-size: 0.9375rem; font-weight: 600; }
.help-row__text span { font-size: 0.8125rem; color: var(--muted); }
.help-row__chevron { flex: none; color: var(--muted); }
.help-row__chevron svg { width: 18px; height: 18px; }
textarea.sub-textarea {
  width: 100%; min-height: 110px; padding: 0.75rem; resize: vertical;
  background: rgba(255, 255, 255, 0.06); border: 1px solid var(--card-line); border-radius: 10px; color: var(--text); font: inherit;
}
.device-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.5rem; font-size: 0.875rem; }
.device-list li { display: flex; justify-content: space-between; gap: 1rem; }
.device-list dt, .device-list .k { color: var(--muted); }
.btn--inline { width: auto; display: inline-flex; margin-top: 1rem; padding: 0.55rem 1.5rem; min-height: 42px; }

/* Portrait mode (set below, Orientation screen): rotates the whole device menu too, not just
   the live show, so the moment you save it the screen you're looking at is already correct for
   a portrait-mounted panel — same technique as body.fs-portrait on the live show. */
body.fs-portrait {
  position: absolute;
  top: 0;
  left: 100%;
  width: 100vh;
  height: 100vw;
  transform-origin: 0 0;
  transform: rotate(90deg);
}
</style>
</head>
<body<?= $orientation === 'portrait' ? ' class="fs-portrait"' : '' ?>>

<?php
// Tiny inline icon set — no external icon library, kept self-contained like the rest of the project.
function xs_device_icon($name)
{
    $icons = [
        'show' => '<path d="M4 5h16v12H4z" stroke-width="1.7"/><path d="M9.5 9l5 3-5 3z" fill="currentColor" stroke="none"/><path d="M2 19h20" stroke-width="1.7" stroke-linecap="round"/>',
        'sliders' => '<path d="M4 6h16M4 12h16M4 18h16" stroke-width="1.7" stroke-linecap="round"/><circle cx="9" cy="6" r="2" fill="#140a28"/><circle cx="15" cy="12" r="2" fill="#140a28"/><circle cx="7" cy="18" r="2" fill="#140a28"/>',
        'rotate' => '<rect x="7" y="2.5" width="10" height="16" rx="2" stroke-width="1.7"/><path d="M20 10.5a5 5 0 1 1-2-4" stroke-width="1.7" stroke-linecap="round" fill="none"/><path d="M20 6v4h-4" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
        'info' => '<circle cx="12" cy="12" r="9" stroke-width="1.7"/><path d="M12 11v5.5" stroke-width="1.9" stroke-linecap="round"/><circle cx="12" cy="7.75" r="1.1" fill="currentColor" stroke="none"/>',
        'portrait' => '<rect x="8" y="3" width="8" height="18" rx="1.5" stroke-width="1.7"/>',
        'landscape' => '<rect x="3" y="8" width="18" height="8" rx="1.5" stroke-width="1.7"/>',
        'back' => '<path d="M15 5l-7 7 7 7" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
        'chevron' => '<path d="M9 5l7 7-7 7" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
        'headset' => '<path d="M4 13v-1a8 8 0 0 1 16 0v1" stroke-width="1.7" stroke-linecap="round" fill="none"/><rect x="3" y="13" width="4" height="6" rx="2" stroke-width="1.7"/><rect x="17" y="13" width="4" height="6" rx="2" stroke-width="1.7"/>',
        'chat' => '<path d="M12 3a9 9 0 0 0-7.75 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3z" stroke-width="1.7" fill="none" stroke-linejoin="round"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2" stroke-width="1.7"/><path d="M4 7l8 6 8-6" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
        'ticket' => '<rect x="5" y="4" width="14" height="17" rx="2" stroke-width="1.7"/><rect x="9" y="2.3" width="6" height="3" rx="1" stroke-width="1.7"/><path d="M8.5 11.5h7M8.5 15h4.5" stroke-width="1.7" stroke-linecap="round"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
?>

<?php if ($screen === 'login'): ?>
<main class="auth">
  <form class="auth__card" method="post" action="NexShow.php">
    <input type="hidden" name="csrf" value="<?= e($deviceCsrf) ?>">
    <input type="hidden" name="action" value="device_login">
    <img class="auth__logo" src="images/nexshow-logo.png" alt="NexShoW">
    <h1>Sign in</h1>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <label class="field"><span>Username</span><input type="text" name="username" required autofocus autocomplete="username" maxlength="80"></label>
    <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Sign in</button>
  </form>
</main>

<?php elseif ($screen === 'menu'): ?>
<div class="menu-wrap">
  <div class="menu-top">
    <div class="menu-top__brand"><?= e($pageTitle) ?></div>
    <div class="menu-top__right">
      <span data-clock>&nbsp;</span>
      <form method="post" action="NexShow.php">
        <input type="hidden" name="csrf" value="<?= e($deviceCsrf) ?>">
        <input type="hidden" name="action" value="device_logout">
        <button class="menu-logout" type="submit">Log out</button>
      </form>
    </div>
  </div>
  <div class="menu-body">
    <h1>Menu</h1>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <div class="tiles-wrap">
      <nav class="tiles">
        <a class="tile" href="NexShow.php?show=1"><span class="tile__icon"><?= xs_device_icon('show') ?></span><span class="tile__label">NexShoW</span></a>
        <a class="tile" href="admin.php?view=settings"><span class="tile__icon"><?= xs_device_icon('sliders') ?></span><span class="tile__label">Frame Setting</span></a>
        <a class="tile" href="NexShow.php?screen=orientation"><span class="tile__icon"><?= xs_device_icon('rotate') ?></span><span class="tile__label">Orientation</span></a>
        <a class="tile" href="NexShow.php?screen=info"><span class="tile__icon"><?= xs_device_icon('info') ?></span><span class="tile__label">Product information</span></a>
      </nav>
    </div>
  </div>
</div>
<script>
(function () {
  var el = document.querySelector('[data-clock]');
  if (!el) return;
  function tick() {
    el.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  tick(); setInterval(tick, 15000);
})();
</script>

<?php elseif ($screen === 'orientation'): ?>
<div class="sub-wrap">
  <a class="sub-back" href="NexShow.php"><?= xs_device_icon('back') ?> Menu</a>
  <h1>Orientation</h1>
  <?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
  <div class="sub-panel">
    <p class="hint">Sets whether the live show is laid out for a landscape or a portrait-mounted screen.</p>
    <form method="post" action="NexShow.php">
      <input type="hidden" name="csrf" value="<?= e($deviceCsrf) ?>">
      <input type="hidden" name="action" value="save_orientation">
      <div class="orient-options">
        <label class="orient-opt">
          <input type="radio" name="orientation" value="landscape"<?= $orientation !== 'portrait' ? ' checked' : '' ?>>
          <span class="orient-opt__card"><?= xs_device_icon('landscape') ?>Landscape</span>
        </label>
        <label class="orient-opt">
          <input type="radio" name="orientation" value="portrait"<?= $orientation === 'portrait' ? ' checked' : '' ?>>
          <span class="orient-opt__card"><?= xs_device_icon('portrait') ?>Portrait</span>
        </label>
      </div>
      <button class="btn btn--inline" type="submit">Save</button>
    </form>
  </div>
</div>

<?php elseif ($screen === 'info'): ?>
<div class="sub-wrap">
  <a class="sub-back" href="NexShow.php"><?= xs_device_icon('back') ?> Menu</a>
  <h1>Product information</h1>
  <?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
  <div class="sub-panel">
    <div class="info-block">
      <h2>Product</h2>
      <div class="info-card"><strong><?= e($pageTitle) ?></strong></div>
    </div>
    <?php $supportWhatsapp = '917385250853'; $supportEmail = 'support@abstudiodigital.in'; ?>
    <div class="help-card">
      <div class="help-card__head">
        <span class="help-card__icon"><?= xs_device_icon('headset') ?></span>
        <div>
          <h2 class="help-card__title">Help &amp; Support</h2>
          <p class="help-card__sub"><?= e($pageTitle) ?> &middot; We're here to help</p>
        </div>
      </div>
      <a class="help-row" href="https://wa.me/<?= e($supportWhatsapp) ?>" target="_blank" rel="noopener">
        <span class="help-row__icon help-row__icon--whatsapp"><?= xs_device_icon('chat') ?></span>
        <span class="help-row__text"><strong>WhatsApp Support</strong><span>Chat with our support team</span></span>
        <span class="help-row__chevron"><?= xs_device_icon('chevron') ?></span>
      </a>
      <a class="help-row" href="mailto:<?= e($supportEmail) ?>">
        <span class="help-row__icon help-row__icon--mail"><?= xs_device_icon('mail') ?></span>
        <span class="help-row__text"><strong>Email Support</strong><span>Send us your issue or query</span></span>
        <span class="help-row__chevron"><?= xs_device_icon('chevron') ?></span>
      </a>
      <a class="help-row" href="mailto:<?= e($supportEmail) ?>?subject=Support%20ticket">
        <span class="help-row__icon help-row__icon--ticket"><?= xs_device_icon('ticket') ?></span>
        <span class="help-row__text"><strong>Raise a Ticket</strong><span>Track and manage support requests</span></span>
        <span class="help-row__chevron"><?= xs_device_icon('chevron') ?></span>
      </a>
    </div>
    <div class="info-block">
      <h2>Device information</h2>
      <div class="info-card"><ul class="device-list" data-device-info><li><span class="k">Loading&hellip;</span></li></ul></div>
    </div>
  </div>
</div>
<script>
(function () {
  var list = document.querySelector('[data-device-info]');
  if (!list) return;
  var rows = [
    ['Platform', navigator.platform || '—'],
    ['Language', navigator.language || '—'],
    ['Screen', screen.width + '×' + screen.height],
    ['Viewport', window.innerWidth + '×' + window.innerHeight],
    ['User agent', navigator.userAgent],
  ];
  list.innerHTML = rows.map(function (r) {
    return '<li><span class="k">' + r[0] + '</span><span>' + r[1].replace(/</g, '&lt;') + '</span></li>';
  }).join('');
})();
</script>
<?php endif; ?>

<script>
// TV remote navigation: most set-top/smart-TV browsers deliver the D-pad as plain
// ArrowUp/Down/Left/Right + Enter keydown events but, unlike a desktop browser, don't
// always move focus between links/buttons/inputs on their own — so we do it ourselves,
// picking whichever focusable element is nearest in the pressed direction.
(function () {
  var SEL = 'a[href], button, input:not([type="hidden"]):not(:disabled), [tabindex]:not([tabindex="-1"])';

  function focusables() {
    return Array.prototype.filter.call(document.querySelectorAll(SEL), function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function center(el) {
    var r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  }

  function moveFocus(dir) {
    var all = focusables();
    if (!all.length) return;
    var current = document.activeElement;
    if (!current || current === document.body || all.indexOf(current) === -1) {
      all[0].focus();
      return;
    }
    var cur = center(current), best = null, bestScore = Infinity;
    all.forEach(function (el) {
      if (el === current) return;
      var p = center(el), dx = p.x - cur.x, dy = p.y - cur.y, primary, cross;
      if (dir === 'ArrowRight') { primary = dx; cross = dy; }
      else if (dir === 'ArrowLeft') { primary = -dx; cross = dy; }
      else if (dir === 'ArrowDown') { primary = dy; cross = dx; }
      else { primary = -dy; cross = dx; }
      if (primary <= 0) return; // only candidates actually in that direction
      // Classic spatial-nav heuristic: mostly-aligned neighbours win over merely-closer ones.
      var score = primary + Math.abs(cross) * 2;
      if (score < bestScore) { bestScore = score; best = el; }
    });
    if (best) best.focus();
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
      var el = document.activeElement;
      // Enter/OK on a radio (the Orientation picker) selects it — browsers only do this for
      // Space, but a remote's OK button sends Enter.
      if (el && el.tagName === 'INPUT' && el.type === 'radio' && !el.checked) {
        el.checked = true;
        el.dispatchEvent(new Event('change', { bubbles: true }));
        e.preventDefault();
      }
      return;
    }
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var active = document.activeElement;
    // Inside a text field, left/right should move the text caret, not jump focus.
    if (active && active.tagName === 'INPUT' && /^(text|password|email|search|tel|url)$/.test(active.type) &&
        (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) return;
    e.preventDefault();
    moveFocus(e.key);
  });

  // Put the first remote-reachable control in real keyboard focus on load, so typing works
  // immediately with no OK press first. On the menu that's the first tile (not the small
  // top-corner Log out button); everywhere else it's the field marked [autofocus] in the HTML.
  // The HTML [autofocus] attribute, and even an explicit .focus() call, are not reliably
  // honoured by every embedded/TV WebView, so this fires more than once (DOMContentLoaded,
  // window load, and a short delay after that) rather than trusting any single attempt.
  function focusFirst() {
    if (document.activeElement && document.activeElement !== document.body) return;
    var first = document.querySelector('.tile') || focusables()[0];
    if (first) first.focus();
  }
  document.addEventListener('DOMContentLoaded', focusFirst);
  window.addEventListener('load', focusFirst);
  setTimeout(focusFirst, 300);
})();
</script>

</body>
</html>
<?php
    exit;
}

// =====================================================================
//  ?show=1 and ?preview=1 both reach here (they're what bypasses the
//  device gate above). Each resolves its own company: ?show=1 from the
//  device session — only a device that's logged in to its own company
//  may watch that company's show — and ?preview=1 from admin.php's own
//  session (its iframe shares the browser's cookies, so the admin who's
//  editing sees their own company's preview, no one else's).
// =====================================================================
$companyId = null;
if (isset($_GET['preview'])) {
    session_name('nexshow_admin');
    session_start();
    $adminExpired = !empty($_SESSION['xs_expires_at']) && strtotime($_SESSION['xs_expires_at']) <= time();
    if (!$adminExpired && !empty($_SESSION['xs_auth']) && !empty($_SESSION['xs_company_id'])) {
        $companyId = (int) $_SESSION['xs_company_id'];
    }
} else {
    session_name('nexshow_device');
    session_start();
    $deviceExpired = !empty($_SESSION['xs_device_expires_at']) && strtotime($_SESSION['xs_device_expires_at']) <= time();
    $deviceOk = !$deviceExpired && !empty($_SESSION['xs_device_auth']) && !empty($_SESSION['xs_device_company_id']) && !empty($_SESSION['xs_device_user_id']);
    $deviceKicked = false;
    $deviceDeactivated = false;
    if ($deviceOk) {
        // Same checks as the device gate above: a fresh login elsewhere overwrites
        // device_session_token, and an admin can deactivate this user mid-session — either
        // signs this (now stale) session out too.
        $currentDeviceUser = xs_db_find_user_by_id((int) $_SESSION['xs_device_user_id']);
        if ($currentDeviceUser !== null && $currentDeviceUser['status'] !== 'active') {
            $deviceOk = false;
            $deviceDeactivated = true;
        } elseif ($currentDeviceUser !== null && (string) $currentDeviceUser['device_session_token'] !== (string) ($_SESSION['xs_device_token'] ?? '')) {
            $deviceOk = false;
            $deviceKicked = true;
        }
    }
    if ($deviceOk) {
        $companyId = (int) $_SESSION['xs_device_company_id'];
    } elseif (isset($_GET['check'])) {
        // The polling endpoint below needs to actually respond (not redirect) even when the
        // session just went invalid, so an already-open ?show=1 page can notice and leave —
        // a redirect here would just hand fetch() a login-page HTML body instead of JSON.
    } else {
        if ($deviceExpired) {
            $_SESSION = [];
            $_SESSION['device_flash'] = ['error', 'That account has expired. Contact your admin.'];
        } elseif ($deviceDeactivated) {
            $_SESSION = [];
            $_SESSION['device_flash'] = ['error', 'This account has been deactivated. Contact your admin.'];
        } elseif ($deviceKicked) {
            $_SESSION = [];
            $_SESSION['device_flash'] = ['error', 'Signed out — this account signed in on another device.'];
        }
        header('Location: NexShow.php');
        exit;
    }
}
// Lightweight polling endpoint for the live show (see the auto-reload script near the end of
// this file): NexShow.php?show=1&check=1 returns a cheap fingerprint of this company's frames
// (so an already-open show can notice admin.php changes without a human reloading by hand) and
// whether this device's session is still the valid one, so a second device logging in as the
// same user kicks the first one out of an already-open show, not just the menu screens.
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'sig' => $companyId !== null ? xs_db_frames_signature($companyId) : null,
        'valid' => !isset($_GET['preview']) ? (isset($deviceOk) ? $deviceOk : true) : true,
    ]);
    exit;
}

if ($companyId !== null) {
    [$pageTitle, $slider, $slides] = xs_load_company($companyId, $pageTitle, $slider, $slides);
}

$count = count($slides);
$bp = (int) $slider['mobileBreakpoint'];
$autoOverlay = ['left' => 'left', 'right' => 'right', 'center' => 'full'];
$tags = xs_db_list_tags((int) $companyId); // {{name}} placeholders usable inside a frame's table cells

/** Decodes a frame's tableData JSON into a safe, well-shaped array, or null if there's no table to show. */
function xs_frame_table($json, array $tags)
{
    if (!$json) return null;
    $t = json_decode($json, true);
    if (!is_array($t) || empty($t['enabled']) || empty($t['rows']) || !is_array($t['rows'])) return null;

    $rows = [];
    foreach ($t['rows'] as $row) {
        if (!is_array($row)) continue;
        $cells = [];
        foreach ($row as $cell) $cells[] = xs_apply_tags((string) $cell, $tags);
        if ($cells) $rows[] = $cells;
    }
    if (!$rows) return null;

    $hex = function ($v, $fallback) {
        $v = (string) $v;
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? $v : $fallback;
    };
    // One optional hex (or null for "no tint") per row/column, capped to the grid's actual size.
    $hexList = function ($list, $n) use ($hex) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $v = is_array($list) && isset($list[$i]) ? (string) $list[$i] : '';
            $out[] = $v !== '' ? $hex($v, null) : null;
        }
        return $out;
    };
    $cols = max(array_map('count', $rows));

    return [
        'rows'           => $rows,
        'headerRow'      => !empty($t['headerRow']),
        'hAlign'         => in_array($t['hAlign'] ?? '', ['left', 'center', 'right'], true) ? $t['hAlign'] : 'center',
        'vAlign'         => in_array($t['vAlign'] ?? '', ['top', 'center', 'bottom'], true) ? $t['vAlign'] : 'center',
        'width'          => max(0, min(100, (int) ($t['width'] ?? 0))), // 0 = size to fit the content
        'fontSize'       => max(10, min(200, (int) ($t['fontSize'] ?? 16))),
        'headerFontSize' => max(10, min(200, (int) ($t['headerFontSize'] ?? $t['fontSize'] ?? 16))),
        'textColor'      => $hex($t['textColor'] ?? '', '#ffffff'),
        'headerColor'    => $hex($t['headerColor'] ?? '', '#ff2d8f'),
        'borderColor'    => $hex($t['borderColor'] ?? '', '#ffffff'),
        'borderWidth'    => max(0, min(8, (int) ($t['borderWidth'] ?? 1))),
        'bgOpacity'      => max(0, min(1, (float) ($t['bgOpacity'] ?? 0.55))),
        'rowColors'      => $hexList($t['rowColors'] ?? [], count($rows)),
        'colColors'      => $hexList($t['colColors'] ?? [], $cols),
    ];
}

// Pre-compute everything each slide needs.
$prepared = [];
foreach ($slides as $i => $slide) {
    $s = array_merge($slideDefaults, $slide);
    $s['duration'] = $s['duration'] ?? $slider['duration'];
    $s['transition'] = $s['transition'] ?? $slider['transition'];
    $s['imageUrl'] = img_url($s['image'] ?? null);
    $s['mobileUrl'] = img_url($s['mobileImage'] ?? null);
    $s['imageIsVideo'] = xs_is_video_path($s['image'] ?? ''); // the image field doubles as a video upload
    $s['overlayDir'] = $s['overlayDirection'] === 'auto' ? ($autoOverlay[$s['textAlign']] ?? 'full') : $s['overlayDirection'];
    $s['table'] = xs_frame_table($s['tableData'] ?? null, $tags);

    $vars = [
        '--focal'        => $s['focalPoint'],
        '--focal-mobile' => $s['mobileFocalPoint'] ?? $s['focalPoint'],
        '--overlay'      => $s['overlay'] ? $s['overlayStrength'] : 0,
        '--text-color'   => $s['textColor'],
        '--title-font'   => $s['fontFamily'],
        '--title-size'   => $s['titleSize'],
        '--title-weight' => $s['titleWeight'],
        '--offset-x'     => $s['offsetX'],
        '--offset-y'     => $s['offsetY'],
        '--kb-duration'  => ($s['duration'] + 2000) . 'ms',
        '--dir'          => 1,
    ];
    $style = '';
    foreach ($vars as $k => $v) {
        if ($v !== null) $style .= $k . ':' . $v . ';';
    }
    $s['style'] = $style;
    $prepared[] = $s;
}

// ?frame=N opens on a given frame; ?preview=1 (used by admin.php) turns autoplay off.
$start = max(0, min($count - 1, (int) ($_GET['frame'] ?? 0)));
if (!empty($_GET['preview'])) $slider['autoplay'] = false;

$first = $prepared[$start] ?? null;
$jsConfig = [
    'start'          => $start,
    'autoplay'       => (bool) $slider['autoplay'],
    'transitionMs'   => (int) $slider['transitionMs'],
    'pauseOnHover'   => (bool) $slider['pauseOnHover'],
    'swipeThreshold' => (int) $slider['swipeThreshold'],
    'mobileBreakpoint' => $bp,
    'durations'      => array_map(function ($s) { return (int) $s['duration']; }, $prepared),
    // Only the real show polls for frame changes and auto-reloads — not the admin preview iframe.
    'checkUrl'       => (isset($_GET['show']) && $companyId !== null) ? 'NexShow.php?show=1&check=1' : null,
    'initialSig'     => $companyId !== null ? xs_db_frames_signature($companyId) : null,
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="theme-color" content="#000000" />
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:opsz,wght@6..96,400;6..96,500;6..96,600&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet" />
<?php if ($first && $first['imageUrl']):
    $isPortrait = $slider['orientation'] === 'portrait';
?>
<!-- First slide image preloaded so the opening frame is never blank. Portrait mode always shows
     the Phone image (see [data-breakpoint] below), so preload that one unconditionally instead
     of gating it on a viewport width that the rotated page never actually reaches. -->
<?php if ($first['mobileUrl'] && $isPortrait): ?>
<link rel="preload" as="image" href="<?= e($first['mobileUrl']) ?>" />
<?php else: ?>
<link rel="preload" as="image" href="<?= e($first['imageUrl']) ?>"<?= $first['mobileUrl'] ? ' media="(min-width: ' . ($bp + 1) . 'px)"' : '' ?> />
<?php if ($first['mobileUrl']): ?>
<link rel="preload" as="image" href="<?= e($first['mobileUrl']) ?>" media="(max-width: <?= $bp ?>px)" />
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<style>
/* ---------- Global ---------- */
:root {
  --font-display: 'Bodoni Moda', 'Didot', 'Bodoni 72', Georgia, serif;
  --font-ui: 'Instrument Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  --accent: #ff2d8f; /* neon pink: progress line, eyebrow, focus rings */
}
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
html, body {
  overflow: hidden; /* the slider is the whole page — no scrollbars */
  overscroll-behavior: none;
  background: #000;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
button { font: inherit; }

/* ---------- Slider ---------- */
.fs-slider {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: #000;
  color: #fff;
  user-select: none;
  -webkit-user-select: none;
  touch-action: pan-y;
  cursor: grab;
}
.fs-slider:active { cursor: grabbing; }
.fs-slider__track { position: absolute; inset: 0; }
.fs-slider--empty {
  display: grid;
  place-items: center;
  font-family: var(--font-ui);
  color: rgba(255, 255, 255, 0.7);
  cursor: default;
}

/* ---------- Slide: layering & transitions ---------- */
.slide {
  position: absolute;
  inset: 0;
  opacity: 0;
  visibility: hidden;
  z-index: 0;
  transition:
    opacity var(--t) cubic-bezier(0.4, 0, 0.2, 1),
    transform var(--t) cubic-bezier(0.2, 0.7, 0.2, 1),
    visibility 0s linear var(--t);
}
.slide.is-active {
  opacity: 1;
  visibility: visible;
  z-index: 2;
  transform: none;
  transition:
    opacity var(--t) cubic-bezier(0.4, 0, 0.2, 1),
    transform var(--t) cubic-bezier(0.2, 0.7, 0.2, 1),
    visibility 0s;
}
/* The outgoing slide stays fully visible underneath while the new one fades in on top,
   so there is never a dip to black mid-transition. */
.slide.is-leaving {
  opacity: 1;
  visibility: visible;
  z-index: 1;
  transition: none;
}
.slide--zoom:not(.is-active):not(.is-leaving) { transform: scale(1.08); }
.slide--drift:not(.is-active):not(.is-leaving) { transform: translateX(calc(var(--dir, 1) * 6%)); }
/* Transient state (one forced reflow only, see render()): pins opacity/visibility so nothing
   flickers while the resting zoom/drift transform above gets a chance to actually commit. */
.slide.is-resetting { opacity: 1; visibility: visible; transition: none; }

/* ---------- Image ---------- */
.slide__media {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background: #000;
}
.slide__media picture { display: block; width: 100%; height: 100%; }
.slide__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: var(--focal, 50% 50%);
  transform-origin: var(--focal, 50% 50%);
  pointer-events: none;
}
/* A video (or image) sharing the desktop slot with a Phone image: shown only on the side it
   belongs to. Portrait mode (see body.fs-portrait) always forces the Phone side, regardless of
   the actual viewport width — the page is a rotated landscape viewport playing portrait content,
   so the real window width never crosses the mobile breakpoint on its own. */
[data-breakpoint] { display: none; }
@media (min-width: <?= $bp + 1 ?>px) { [data-breakpoint="desktop"] { display: block; } }
@media (max-width: <?= $bp ?>px) { [data-breakpoint="mobile"] { display: block; } }
body.fs-portrait [data-breakpoint="desktop"] { display: none; }
body.fs-portrait [data-breakpoint="mobile"] { display: block; }
.slide.has-kenburns.is-active .slide__img,
.slide.has-kenburns.is-leaving .slide__img {
  animation: slide-kenburns var(--kb-duration, 7000ms) linear both;
}
@keyframes slide-kenburns {
  from { transform: scale(1); }
  to { transform: scale(1.07); }
}

/* Missing or broken image: a quiet tonal backdrop instead of a blank frame. */
.slide.is-fallback .slide__media {
  background: radial-gradient(120% 90% at 70% 40%, #3a3127 0%, #15120f 55%, #000 100%);
}
.slide.is-fallback picture { display: none; }

/* ---------- Overlay ---------- */
.slide__overlay { position: absolute; inset: 0; pointer-events: none; }
.slide__overlay--left {
  background: linear-gradient(90deg,
    rgba(0, 0, 0, var(--overlay)) 0%,
    rgba(0, 0, 0, calc(var(--overlay) * 0.55)) 40%,
    rgba(0, 0, 0, 0) 75%);
}
.slide__overlay--right {
  background: linear-gradient(270deg,
    rgba(0, 0, 0, var(--overlay)) 0%,
    rgba(0, 0, 0, calc(var(--overlay) * 0.55)) 40%,
    rgba(0, 0, 0, 0) 75%);
}
.slide__overlay--bottom {
  background: linear-gradient(0deg,
    rgba(0, 0, 0, var(--overlay)) 0%,
    rgba(0, 0, 0, calc(var(--overlay) * 0.4)) 45%,
    rgba(0, 0, 0, 0) 75%);
}
.slide__overlay--full { background: rgba(0, 0, 0, calc(var(--overlay) * 0.75)); }
/* A light floor gradient on every slide keeps the controls legible. */
.slide__overlay::after {
  content: '';
  position: absolute;
  inset: auto 0 0 0;
  height: 28%;
  background: linear-gradient(0deg, rgba(0, 0, 0, 0.45), rgba(0, 0, 0, 0));
}

/* ---------- Text layout ---------- */
.slide__layout {
  position: absolute;
  inset: 0;
  display: flex;
  padding:
    calc(clamp(1.5rem, 6vw, 6rem) + env(safe-area-inset-top, 0px))
    clamp(1.5rem, 7vw, 7.5rem)
    calc(clamp(5.5rem, 10vh, 8rem) + env(safe-area-inset-bottom, 0px));
}
.slide__layout.v-top { align-items: flex-start; }
.slide__layout.v-center { align-items: center; }
.slide__layout.v-bottom { align-items: flex-end; }
.slide__layout.h-left { justify-content: flex-start; text-align: left; }
.slide__layout.h-center { justify-content: center; text-align: center; }
.slide__layout.h-right { justify-content: flex-end; text-align: right; }
.slide__content {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  max-width: min(40rem, 100%);
  color: var(--text-color, #fff);
  transform: translate(var(--offset-x, 0), var(--offset-y, 0));
  text-shadow: 0 1px 30px rgba(0, 0, 0, 0.25);
}
.h-center .slide__content { align-items: center; max-width: min(52rem, 100%); }
.h-right .slide__content { align-items: flex-end; }

/* ---------- Data table ---------- */
.slide__table-layout {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: flex;
  padding:
    calc(clamp(1.5rem, 6vw, 6rem) + env(safe-area-inset-top, 0px))
    clamp(1.5rem, 7vw, 7.5rem)
    calc(clamp(5.5rem, 10vh, 8rem) + env(safe-area-inset-bottom, 0px));
  pointer-events: none;
}
.slide__table-layout.v-top { align-items: flex-start; }
.slide__table-layout.v-center { align-items: center; }
.slide__table-layout.v-bottom { align-items: flex-end; }
.slide__table-layout.h-left { justify-content: flex-start; }
.slide__table-layout.h-center { justify-content: center; }
.slide__table-layout.h-right { justify-content: flex-end; }
.slide__table {
  pointer-events: auto;
  border-collapse: collapse;
  background: rgba(10, 9, 8, var(--table-bg-opacity, 0.55));
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  font-family: var(--font-ui);
  color: var(--table-text-color, #fff);
  text-shadow: 0 1px 10px rgba(0, 0, 0, 0.3);
}
.slide__table td, .slide__table th {
  padding: 0.6rem 1.2rem;
  border: var(--table-border-width, 1px) solid var(--table-border-color, rgba(255, 255, 255, 0.18));
  font-size: var(--table-font-size, clamp(0.8125rem, 1.4vw, 1rem));
  white-space: nowrap;
  text-align: center;
}
/* A set width (rather than auto) only sticks if the table actually distributes columns to fit it. */
.slide__table--sized { width: 100%; table-layout: fixed; }
.slide__table--sized td, .slide__table--sized th { white-space: normal; word-break: break-word; }
.slide__table tr.is-header th {
  color: var(--table-header-color, var(--accent));
  font-size: var(--table-header-font-size, var(--table-font-size, inherit));
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

/* ---------- Typography ---------- */
.slide__eyebrow {
  margin: 0 0 1.1rem;
  font-family: var(--font-ui);
  font-size: 0.9375rem;
  font-weight: 500;
  letter-spacing: 0.02em;
  color: var(--accent);
}
.slide__title {
  margin: 0;
  font-family: var(--title-font, var(--font-display));
  font-size: var(--title-size, clamp(2.6rem, 6.4vw, 6rem));
  font-weight: var(--title-weight, 400);
  font-variation-settings: 'opsz' 96;
  line-height: 0.98;
  letter-spacing: -0.02em;
  text-wrap: balance;
}
.slide__desc {
  margin: 1.4rem 0 0;
  max-width: 34ch;
  font-family: var(--font-ui);
  font-size: clamp(1rem, 1.25vw, 1.1875rem);
  line-height: 1.55;
  opacity: 0.86;
  text-wrap: pretty;
}
.slide__btn {
  margin-top: 2.25rem;
  padding: 0.95rem 1.75rem;
  font-family: var(--font-ui);
  font-size: 0.9375rem;
  font-weight: 500;
  color: inherit;
  text-decoration: none;
  text-shadow: none;
  border: 1px solid currentColor;
  border-radius: 999px;
  transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease;
}
.slide__btn:hover { background: var(--text-color, #fff); color: #000; }
.slide__btn:focus-visible { outline: none; }

/* ---------- Entrance: one orchestrated reveal per slide ---------- */
.slide.is-active .slide__content > * { animation: slide-rise 1s cubic-bezier(0.2, 0.7, 0.2, 1) both; }
.slide.is-active .slide__content > :nth-child(1) { animation-delay: 0.35s; }
.slide.is-active .slide__content > :nth-child(2) { animation-delay: 0.5s; }
.slide.is-active .slide__content > :nth-child(3) { animation-delay: 0.65s; }
.slide.is-active .slide__content > :nth-child(4) { animation-delay: 0.8s; }
@keyframes slide-rise {
  from { opacity: 0; transform: translateY(18px); }
  to { opacity: 1; transform: none; }
}

/* ---------- Controls ---------- */
.ctrl-arrow {
  position: absolute;
  top: 50%;
  z-index: 5;
  display: grid;
  place-items: center;
  width: 3.25rem;
  height: 3.25rem;
  margin-top: -1.625rem;
  padding: 0;
  color: #fff;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 50%;
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.3s ease, background-color 0.3s ease, transform 0.3s ease;
}
.ctrl-arrow--prev { left: clamp(0.75rem, 2vw, 2rem); }
.ctrl-arrow--next { right: clamp(0.75rem, 2vw, 2rem); }
.fs-slider:hover .ctrl-arrow { opacity: 0.85; }
.ctrl-arrow:hover { opacity: 1; background: rgba(255, 255, 255, 0.16); }
.ctrl-arrow--prev:active { transform: translateX(-2px); }
.ctrl-arrow--next:active { transform: translateX(2px); }

.ctrl-bar {
  position: absolute;
  left: 0;
  right: 0;
  bottom: env(safe-area-inset-bottom, 0px);
  z-index: 5;
  display: flex;
  justify-content: center;
  align-items: center;
  pointer-events: none;
}
.ctrl-dots { display: flex; gap: 0.25rem; pointer-events: auto; }
.ctrl-dot {
  display: grid;
  place-items: end center; /* track flush with the bottom of the touch target, not centered in it */
  width: 2.75rem; /* 44px touch target */
  height: 2.75rem;
  padding: 0;
  background: none;
  border: 0;
  cursor: pointer;
}
.ctrl-dot__track {
  position: relative;
  display: block;
  width: 1.5rem;
  height: 2px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.35);
  border-radius: 2px;
  transition: width 0.4s ease, background-color 0.3s ease;
}
.ctrl-dot:hover .ctrl-dot__track { background: rgba(255, 255, 255, 0.7); }
.ctrl-dot.is-active .ctrl-dot__track { width: 2.5rem; }
.ctrl-dot__fill {
  position: absolute;
  inset: 0;
  background: var(--accent);
  transform-origin: left center;
}
.ctrl-dot__fill.is-running { animation: ctrl-fill linear both; }
@keyframes ctrl-fill {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}

.ctrl-pause {
  position: absolute;
  right: clamp(1rem, 2.5vw, 2.25rem);
  bottom: calc(clamp(1.25rem, 3.5vh, 2.25rem) + env(safe-area-inset-bottom, 0px));
  z-index: 5;
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  padding: 0;
  color: #fff;
  background: none;
  border: 0;
  border-radius: 50%;
  cursor: pointer;
  opacity: 0.7;
  transition: opacity 0.2s ease;
}
.ctrl-pause:hover { opacity: 1; }
.ctrl-pause[hidden] { display: none; }
.ctrl-arrow:focus-visible,
.ctrl-dot:focus-visible,
.ctrl-pause:focus-visible {
  outline: none;
  opacity: 1;
}

/* ---------- Mobile ---------- */
@media (max-width: <?= $bp ?>px) {
  .slide__img {
    object-position: var(--focal-mobile, var(--focal, 50% 50%));
    transform-origin: var(--focal-mobile, var(--focal, 50% 50%));
  }
  /* Desktop offsets are ignored on phones so text never gets pushed off-screen. */
  .slide__content { transform: none; }
  /* Side gradients don't cover stacked mobile text — switch to a bottom-up wash. */
  .slide__overlay--left,
  .slide__overlay--right {
    background: linear-gradient(0deg,
      rgba(0, 0, 0, var(--overlay)) 0%,
      rgba(0, 0, 0, calc(var(--overlay) * 0.5)) 50%,
      rgba(0, 0, 0, calc(var(--overlay) * 0.15)) 100%);
  }
  .slide__desc { max-width: 30ch; }
  .slide__btn { margin-top: 1.75rem; padding: 0.9rem 1.5rem; }
  .slide__table-layout { padding-left: 1rem; padding-right: 1rem; }
  .slide__table td, .slide__table th { padding: 0.45rem 0.7rem; white-space: normal; }

  /* Phones swipe; keep arrows small and out of the text's way. */
  .ctrl-arrow {
    top: auto;
    bottom: calc(clamp(1.25rem, 3.5vh, 2.25rem) + env(safe-area-inset-bottom, 0px));
    margin-top: 0;
    width: 2.75rem;
    height: 2.75rem;
    opacity: 0.7;
  }
  /* Pause moves to the top corner so the bottom row holds only arrows + dots. */
  .ctrl-pause {
    top: calc(0.75rem + env(safe-area-inset-top, 0px));
    right: 0.75rem;
    bottom: auto;
  }
}

@media (prefers-reduced-motion: reduce) {
  .slide,
  .slide.is-active { transform: none !important; }
  .slide.has-kenburns .slide__img,
  .slide.is-active .slide__content > * { animation: none !important; }
  .ctrl-dot__fill.is-running { animation: none; transform: scaleX(1); }
}

/* Portrait mode (set from the device menu → Orientation): rotates the page 90° so portrait
   content fills a landscape-output panel, and also forces the Phone image on every slide via
   body.fs-portrait under [data-breakpoint] above — the real viewport width never crosses the
   mobile breakpoint on its own once the page is rotated, so that forcing is still needed on
   top of the rotation. Landscape mode shows the normal desktop/TV image, unrotated. */
body.fs-portrait {
  position: absolute;
  top: 0;
  left: 100%;
  width: 100vh;
  height: 100vw;
  transform-origin: 0 0;
  transform: rotate(90deg);
}
</style>
</head>
<body<?= $slider['orientation'] === 'portrait' ? ' class="fs-portrait"' : '' ?>>
<?php if ($count === 0): ?>
<div class="fs-slider fs-slider--empty"><p>No slides yet. Add some from admin</p></div>
<?php if ($jsConfig['checkUrl']): ?>
<script>
// No frames yet — still poll, so the show comes to life on its own once the first one is added.
setInterval(function () {
  fetch(<?= json_encode($jsConfig['checkUrl']) ?>, { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (data) { if (data.sig) location.reload(); })
    .catch(function () {});
}, 20000);
</script>
<?php endif; ?>
<?php else: ?>
<section class="fs-slider" id="slider" aria-roledescription="carousel" aria-label="<?= e($slider['ariaLabel']) ?>" style="--t: <?= (int) $slider['transitionMs'] ?>ms">
  <div class="fs-slider__track" aria-live="off">
<?php foreach ($prepared as $i => $s):
    $d = abs($i - $start);
    $near = $d <= 1 || $d === $count - 1; // active slide and its neighbours load first
    $isStart = $i === $start;
    $classes = ['slide', 'slide--' . $s['transition']];
    if ($isStart) $classes[] = 'is-active';
    if ($s['kenBurns']) $classes[] = 'has-kenburns';
    if (!$s['imageUrl']) $classes[] = 'is-fallback';
    $tag = $i === 0 ? 'h1' : 'h2';
?>
    <div class="<?= e(implode(' ', $classes)) ?>" style="<?= e($s['style']) ?>" role="group" aria-roledescription="slide" aria-label="<?= ($i + 1) . ' of ' . $count ?>"<?= $isStart ? '' : ' aria-hidden="true" inert' ?>>
      <div class="slide__media">
<?php if ($s['imageUrl'] && !$s['imageIsVideo'] && $s['mobileUrl']): ?>
        <!-- Two images, toggled by CSS (see [data-breakpoint] above) rather than a <picture><source>
             media query, so portrait mode can force the Phone one regardless of actual viewport width. -->
        <img class="slide__img" data-breakpoint="desktop" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['imageUrl']) ?>" alt="<?= e($s['alt'] ?? '') ?>" loading="<?= $isStart ? 'eager' : 'lazy' ?>" fetchpriority="<?= $isStart ? 'high' : 'auto' ?>" decoding="async" draggable="false" />
        <img class="slide__img" data-breakpoint="mobile" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['mobileUrl']) ?>" alt="<?= e($s['alt'] ?? '') ?>" loading="<?= $isStart ? 'eager' : 'lazy' ?>" decoding="async" draggable="false" />
<?php elseif ($s['imageUrl'] && !$s['imageIsVideo']): ?>
        <img class="slide__img" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['imageUrl']) ?>" alt="<?= e($s['alt'] ?? '') ?>" loading="<?= $isStart ? 'eager' : 'lazy' ?>" fetchpriority="<?= $isStart ? 'high' : 'auto' ?>" decoding="async" draggable="false" />
<?php elseif ($s['imageIsVideo'] && $s['mobileUrl']): ?>
        <!-- The uploaded video plays on larger screens; the Phone image covers small ones. -->
        <video class="slide__img slide__img--video" data-breakpoint="desktop" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['imageUrl']) ?>" loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video>
        <img class="slide__img" data-breakpoint="mobile" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['mobileUrl']) ?>" alt="<?= e($s['alt'] ?? '') ?>" loading="<?= $isStart ? 'eager' : 'lazy' ?>" decoding="async" draggable="false" />
<?php elseif ($s['imageIsVideo']): ?>
        <video class="slide__img slide__img--video" <?= $near ? 'src' : 'data-src' ?>="<?= e($s['imageUrl']) ?>" loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video>
<?php endif; ?>
      </div>
<?php if ($s['overlay']): ?>
      <div class="slide__overlay slide__overlay--<?= e($s['overlayDir']) ?>" aria-hidden="true"></div>
<?php endif; ?>
      <div class="slide__layout v-<?= e($s['verticalAlign']) ?> h-<?= e($s['textAlign']) ?>">
        <div class="slide__content">
<?php if (!empty($s['eyebrow'])): ?>
          <p class="slide__eyebrow"><?= e($s['eyebrow']) ?></p>
<?php endif; ?>
<?php if (!empty($s['title'])): ?>
          <<?= $tag ?> class="slide__title"><?= e($s['title']) ?></<?= $tag ?>>
<?php endif; ?>
<?php if (!empty($s['description'])): ?>
          <p class="slide__desc"><?= e($s['description']) ?></p>
<?php endif; ?>
<?php if (!empty($s['buttonText']) && !empty($s['buttonUrl'])): ?>
          <a class="slide__btn" href="<?= e($s['buttonUrl']) ?>"<?= $s['openInNewTab'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($s['buttonText']) ?></a>
<?php endif; ?>
        </div>
      </div>
<?php if ($s['table']): $tbl = $s['table'];
    $tblStyle = '--table-font-size:' . $tbl['fontSize'] . 'px;'
        . '--table-header-font-size:' . $tbl['headerFontSize'] . 'px;'
        . '--table-text-color:' . $tbl['textColor'] . ';'
        . '--table-header-color:' . $tbl['headerColor'] . ';'
        . '--table-border-color:' . $tbl['borderColor'] . ';'
        . '--table-border-width:' . $tbl['borderWidth'] . 'px;'
        . '--table-bg-opacity:' . $tbl['bgOpacity'] . ';'
        . ($tbl['width'] > 0 ? 'width:' . $tbl['width'] . '%;' : '');
?>
      <div class="slide__table-layout v-<?= e($tbl['vAlign']) ?> h-<?= e($tbl['hAlign']) ?>">
        <table class="slide__table<?= $tbl['width'] > 0 ? ' slide__table--sized' : '' ?>" style="<?= e($tblStyle) ?>">
<?php foreach ($tbl['rows'] as $ri => $row):
    $isHead = $ri === 0 && $tbl['headerRow'];
    $tag = $isHead ? 'th' : 'td';
?>
          <tr<?= $isHead ? ' class="is-header"' : '' ?>>
<?php foreach ($row as $ci => $cell):
    $tint = $tbl['colColors'][$ci] ?? null;
    if ($tint === null) $tint = $tbl['rowColors'][$ri] ?? null;
    $cellStyle = $tint ? ' style="background-color:' . e($tint) . '"' : '';
?>
            <<?= $tag ?><?= $cellStyle ?>><?= e($cell) ?></<?= $tag ?>>
<?php endforeach; ?>
          </tr>
<?php endforeach; ?>
        </table>
      </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>
<?php if ($count > 1): ?>

  <button type="button" class="ctrl-arrow ctrl-arrow--prev" data-prev aria-label="Previous slide">
    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
  </button>
  <button type="button" class="ctrl-arrow ctrl-arrow--next" data-next aria-label="Next slide">
    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
  </button>

  <div class="ctrl-bar">
    <div class="ctrl-dots" role="group" aria-label="Choose slide">
<?php foreach ($prepared as $i => $s): ?>
      <button type="button" class="ctrl-dot<?= $i === $start ? ' is-active' : '' ?>" data-goto="<?= $i ?>" aria-label="<?= e('Go to slide ' . ($i + 1) . (!empty($s['title']) ? ': ' . $s['title'] : '')) ?>"<?= $i === $start ? ' aria-current="true"' : '' ?>><span class="ctrl-dot__track"></span></button>
<?php endforeach; ?>
    </div>
  </div>

  <button type="button" class="ctrl-pause" data-pause aria-label="Pause slideshow" hidden></button>
<?php endif; ?>
</section>

<script>
(function () {
  var cfg = <?= json_encode($jsConfig) ?>;
  var root = document.getElementById('slider');
  var slides = root.querySelectorAll('.slide');
  var dots = root.querySelectorAll('.ctrl-dot');
  var pauseBtn = root.querySelector('[data-pause]');
  var track = root.querySelector('.fs-slider__track');
  var count = slides.length;

  var ICON_PAUSE = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="7" y="5.5" width="3" height="13" rx="1" fill="currentColor"/><rect x="14" y="5.5" width="3" height="13" rx="1" fill="currentColor"/></svg>';
  var ICON_PLAY = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M8 5.5v13l10-6.5z" fill="currentColor"/></svg>';

  var index = cfg.start, prevIndex = null, direction = 1;
  var hovered = false, focusWithin = false, userPaused = false, tabHidden = document.hidden;
  var timer = null, lastPlaying = null, lastIndex = null;

  var reducedMQ = window.matchMedia('(prefers-reduced-motion: reduce)');
  var hoverMQ = window.matchMedia('(hover: hover) and (pointer: fine)');
  var breakpointMQ = window.matchMedia('(max-width: ' + cfg.mobileBreakpoint + 'px)');

  var wrap = function (i) { return ((i % count) + count) % count; };

  // Broken image/video → quiet tonal backdrop.
  slides.forEach(function (el) {
    el.querySelectorAll('.slide__img').forEach(function (media) {
      var fail = function () { el.classList.add('is-fallback'); };
      media.addEventListener('error', fail);
      if (media.tagName === 'IMG' && media.complete && media.getAttribute('src') && media.naturalWidth === 0) fail();
    });
  });

  // Load an image/video once it is active or next to the active slide, then keep it.
  function loadNear() {
    slides.forEach(function (el, i) {
      var d = Math.abs(i - index);
      if (!(d <= 1 || d === count - 1)) return;
      var src = el.querySelector('source[data-srcset]');
      if (src) { src.srcset = src.dataset.srcset; src.removeAttribute('data-srcset'); }
      el.querySelectorAll('[data-src]').forEach(function (media) {
        media.src = media.dataset.src;
        media.removeAttribute('data-src');
      });
    });
  }

  function autoplayAllowed() {
    return cfg.autoplay && !reducedMQ.matches && count > 1;
  }

  // Background video: plays only on the active slide, only while actually visible (the Phone
  // image can be covering it instead), never with reduced motion, and pauses when the tab is hidden.
  // Tries to play WITH sound first; browsers that block unmuted autoplay reject that play(),
  // so it falls back to muted (which always autoplays) and unmutes itself the instant the page
  // gets any interaction at all — a click/tap/key anywhere, not necessarily on the video.
  var soundUnlocked = true;
  document.addEventListener('pointerdown', unlockSound);
  document.addEventListener('keydown', unlockSound);
  function unlockSound() {
    soundUnlocked = true;
    document.removeEventListener('pointerdown', unlockSound);
    document.removeEventListener('keydown', unlockSound);
    slides.forEach(function (el) {
      el.querySelectorAll('.slide__img--video').forEach(function (video) {
        if (!video.paused) video.muted = false;
      });
    });
  }
  function updateVideos() {
    slides.forEach(function (el, i) {
      el.querySelectorAll('.slide__img--video').forEach(function (video) {
        var canPlay = i === index && !reducedMQ.matches && !tabHidden && video.offsetWidth > 0;
        if (canPlay) {
          video.muted = !soundUnlocked;
          var p = video.play();
          if (p && p.catch) {
            p.catch(function () {
              if (!video.muted) {
                // Unmuted autoplay was blocked by the browser — fall back to muted so the
                // video still plays; unlockSound() will retry with sound on next interaction.
                soundUnlocked = false;
                video.muted = true;
                video.play().catch(function () {});
              }
            });
          }
        } else if (!video.paused) {
          video.pause();
        }
      });
    });
  }

  function isPlaying() {
    return autoplayAllowed() && !userPaused && !tabHidden && !focusWithin &&
      !(cfg.pauseOnHover && hoverMQ.matches && hovered);
  }

  function render() {
    var playing = isPlaying();
    var duration = cfg.durations[index];

    root.style.setProperty('--t', (reducedMQ.matches ? 1 : cfg.transitionMs) + 'ms');
    track.setAttribute('aria-live', playing ? 'off' : 'polite');

    slides.forEach(function (el, i) {
      var active = i === index;
      // With few slides (2 is the common case), a slide can go straight from "leaving" back to
      // "active" in this same call — it never passes through the resting state that sets the
      // zoom/drift transition's starting transform (scale(1.08) / offset), so transform never
      // actually changes value and no transition fires. Force that resting frame to commit first
      // (is-resetting pins opacity/visibility so nothing flickers while only transform resets).
      if (active && el.classList.contains('is-leaving')) {
        el.classList.remove('is-leaving');
        el.classList.add('is-resetting');
        void el.offsetWidth; // flush the resting transform before re-applying is-active
        el.classList.remove('is-resetting');
      }
      el.classList.toggle('is-active', active);
      el.classList.toggle('is-leaving', i === prevIndex && !active);
      el.style.setProperty('--dir', direction);
      if (active) { el.removeAttribute('aria-hidden'); el.inert = false; el.removeAttribute('inert'); }
      else { el.setAttribute('aria-hidden', 'true'); el.inert = true; el.setAttribute('inert', ''); }
    });

    updateVideos();

    // Progress line: rebuilt whenever the slide or play state changes so the animation restarts.
    if (index !== lastIndex || playing !== lastPlaying) {
      dots.forEach(function (dot, i) {
        var active = i === index;
        dot.classList.toggle('is-active', active);
        if (active) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
        var trackEl = dot.firstElementChild;
        trackEl.innerHTML = '';
        if (active) {
          var fill = document.createElement('span');
          fill.className = 'ctrl-dot__fill' + (playing ? ' is-running' : '');
          fill.style.animationDuration = duration + 'ms';
          trackEl.appendChild(fill);
        }
      });
    }

    if (pauseBtn) {
      pauseBtn.hidden = !autoplayAllowed();
      pauseBtn.setAttribute('aria-label', userPaused ? 'Play slideshow' : 'Pause slideshow');
      pauseBtn.innerHTML = userPaused ? ICON_PLAY : ICON_PAUSE;
    }

    // Autoplay: restart the timer on every slide change or play/pause flip.
    if (index !== lastIndex || playing !== lastPlaying) {
      clearTimeout(timer);
      timer = null;
      if (playing && duration) timer = setTimeout(next, duration);
    }

    lastIndex = index;
    lastPlaying = playing;
  }

  function goTo(target, dir) {
    if (count < 2) return;
    var n = wrap(target);
    if (n === index) return;
    prevIndex = index;
    direction = dir != null ? dir : (n > index ? 1 : -1);
    index = n;
    loadNear();
    render();
  }
  function next() { goTo(index + 1, 1); }
  function prev() { goTo(index - 1, -1); }

  // Controls
  root.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t || !root.contains(t)) return;
    if (t.hasAttribute('data-prev')) prev();
    else if (t.hasAttribute('data-next')) next();
    else if (t.hasAttribute('data-goto')) goTo(+t.dataset.goto);
    else if (t.hasAttribute('data-pause')) { userPaused = !userPaused; render(); }
  });

  // Pause on hover / keyboard focus / hidden tab.
  root.addEventListener('mouseenter', function () { hovered = true; render(); });
  root.addEventListener('mouseleave', function () { hovered = false; render(); });
  root.addEventListener('focusin', function (e) {
    var fv = false;
    try { fv = e.target.matches(':focus-visible'); } catch (err) {}
    if (fv) { focusWithin = true; render(); }
  });
  root.addEventListener('focusout', function (e) {
    if (!root.contains(e.relatedTarget)) { focusWithin = false; render(); }
  });
  document.addEventListener('visibilitychange', function () { tabHidden = document.hidden; render(); });
  [reducedMQ, hoverMQ, breakpointMQ].forEach(function (mq) {
    if (mq.addEventListener) mq.addEventListener('change', render);
    else if (mq.addListener) mq.addListener(render);
  });

  // Keyboard arrows anywhere on the page (the slider is the page).
  window.addEventListener('keydown', function (e) {
    var t = e.target;
    if (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
  });

  // Mouse drag + touch swipe via Pointer Events. Drags starting on a link/button are ignored
  // so CTAs stay clickable, and a click that ends a drag is swallowed.
  var start = null, dragged = false;
  root.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (e.target.closest('a, button')) return;
    start = { x: e.clientX, y: e.clientY };
    dragged = false;
  });
  root.addEventListener('pointermove', function (e) {
    if (start && Math.abs(e.clientX - start.x) > 8) dragged = true;
  });
  root.addEventListener('pointerup', function (e) {
    if (!start) return;
    var dx = e.clientX - start.x, dy = e.clientY - start.y;
    start = null;
    if (Math.abs(dx) < cfg.swipeThreshold || Math.abs(dx) < Math.abs(dy)) return;
    if (dx < 0) next(); else prev();
  });
  var cancel = function () { start = null; };
  root.addEventListener('pointercancel', cancel);
  root.addEventListener('pointerleave', cancel);
  root.addEventListener('click', function (e) {
    if (dragged) { e.preventDefault(); e.stopPropagation(); dragged = false; }
  }, true);

  render();

  // Live show only (see $jsConfig in PHP): poll for admin.php changes (add/edit/reorder/delete
  // a frame) and reload automatically, so a screen that's just sitting there doesn't need a
  // human to refresh it by hand after someone edits the show. Also poll for this device's own
  // session going stale (same user signed in on another device) and bounce back to login.
  if (cfg.checkUrl) {
    setInterval(function () {
      fetch(cfg.checkUrl, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.valid === false || (data.sig && data.sig !== cfg.initialSig)) location.reload();
        })
        .catch(function () {}); // offline for a moment — just try again next interval
    }, 20000);
  }
})();
</script>
<?php endif; ?>
</body>
</html>
