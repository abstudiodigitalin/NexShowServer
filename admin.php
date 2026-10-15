<?php
/**
 * NexShoW admin: add, edit, reorder, duplicate and delete slider frames.
 *
 * Open admin.php in the browser. The first visit asks you to choose a password.
 * Frames, settings and the admin password all live in the NexShoW_db database
 * (see db-config.php). Uploaded photos/videos go to images/Frame/.
 */

define('NEXSHOW_CONFIG_ONLY', true);
require __DIR__ . '/NexShow.php'; // defaults + db-config.php: $slider, $slideDefaults, $pageTitle; helper e()

const XS_MAX_UPLOAD = 15 * 1024 * 1024;
const XS_MAX_VIDEO_UPLOAD = 40 * 1024 * 1024;

session_name('nexshow_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// =====================================================================
//  Helpers
// =====================================================================

function xs_redirect($query = '', $msg = '', $type = 'ok')
{
    if ($msg !== '') $_SESSION['flash'] = [$type, $msg];
    header('Location: admin.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

/** Sends a plain-text email via PHP's mail() (handed off to whatever MTA/SMTP relay is
 *  configured in php.ini — this is a thin wrapper, not a delivery guarantee). Used only for
 *  "forgot password" reset links. Never throws: a mail failure must not reveal whether an
 *  account/email exists, so callers always show the same message either way. */
function xs_send_mail($to, $subject, $body)
{
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $headers = "From: NexShoW <no-reply@$host>\r\nContent-Type: text/plain; charset=UTF-8";
    try {
        return @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

function xs_cut($v, $max)
{
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/** Plain text: trimmed, no control characters, length-capped. */
function xs_text($v, $max = 200)
{
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', trim((string) $v));
    return xs_cut((string) $v, $max);
}

/** A value that ends up inside a CSS custom property. */
function xs_css($v, $max = 80)
{
    return xs_cut(trim(preg_replace('/[;{}<>"\\\\]/', '', (string) $v)), $max);
}

/** Links and image paths: no script/data schemes. */
function xs_url($v)
{
    $v = trim((string) $v);
    if ($v === '' || preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $v)) return '';
    return xs_cut($v, 500);
}

function xs_enum($v, array $allowed, $fallback)
{
    return in_array($v, $allowed, true) ? $v : $fallback;
}

function xs_find(array $frames, $id)
{
    foreach ($frames as $i => $f) {
        if (($f['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function xs_new_id($title, array $frames)
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
    $base = substr($base !== '' ? $base : 'frame', 0, 40);
    $ids = array_column($frames, 'id');
    $id = $base;
    for ($n = 2; in_array($id, $ids, true); $n++) $id = $base . '-' . $n;
    return $id;
}

/** Image value → URL the browser can load. */
function xs_thumb($v)
{
    return (string) $v;
}

/** Web-relative upload folder for one company — keeps "unused file" cleanup and the Library
 *  picker from ever touching another company's images. */
function xs_company_frame_dir($companyId)
{
    return XS_FRAME_DIR . '/' . (int) $companyId;
}

function xs_frame_files($companyId)
{
    $webDir = xs_company_frame_dir($companyId);
    $dir = __DIR__ . '/' . $webDir;
    $out = [];
    foreach (is_dir($dir) ? scandir($dir) : [] as $name) {
        if (preg_match('/\.(jpe?g|png|webp|gif|mp4|webm|mov|m4v)$/i', $name)) $out[$webDir . '/' . $name] = filemtime("$dir/$name");
    }
    arsort($out);
    return array_keys($out);
}

function xs_used_images(array $frames)
{
    $used = [];
    foreach ($frames as $f) {
        foreach (['image', 'mobileImage'] as $k) if (!empty($f[$k])) $used[$f[$k]] = true;
    }
    return $used;
}

/** Saves an uploaded photo (or, when $allowVideo, a video) and returns its path, or null when no file was chosen. */
function xs_upload($companyId, $field, $allowVideo = false)
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    $maxUpload = $allowVideo ? XS_MAX_VIDEO_UPLOAD : XS_MAX_UPLOAD;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $maxUpload) {
        throw new RuntimeException('That file is too large. Keep it under ' . min($maxUpload >> 20, (int) ini_get('upload_max_filesize')) . ' MB.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed. Please try again.');

    $videoTypes = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    $mime = $allowVideo && function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : null;
    if ($mime && isset($videoTypes[$mime])) {
        $ext = $videoTypes[$mime];
    } else {
        $info = @getimagesize($f['tmp_name']);
        $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$info || !isset($imageTypes[$info['mime']])) {
            throw new RuntimeException($allowVideo
                ? 'Please upload a JPG, PNG, WebP, GIF image or an MP4/WebM video.'
                : 'Please upload a JPG, PNG, WebP or GIF image.');
        }
        $ext = $imageTypes[$info['mime']];
    }

    $webDir = xs_company_frame_dir($companyId);
    $dir = __DIR__ . '/' . $webDir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the images/Frame folder.');
    $name = 'xs-' . date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not save the uploaded file.');
    return $webDir . '/' . $name;
}

/** Builds a frame from the edit form. Blank optional fields are left out so defaults apply. */
/** Builds the table-data JSON from the edit form: tableEnabled/tableHeaderRow/tableHAlign/tableVAlign
 *  are plain fields; tableRows is the grid editor's JSON (just the cells). Null if there's no usable table. */
function xs_table_from_post(array $p)
{
    $raw = (string) ($p['tableRows'] ?? '');
    $grid = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($grid) || empty($grid['rows']) || !is_array($grid['rows'])) return null;

    $rows = [];
    foreach (array_slice($grid['rows'], 0, 20) as $row) { // cap 20 rows
        if (!is_array($row)) continue;
        $cells = [];
        foreach (array_slice($row, 0, 10) as $cell) { // cap 10 columns
            $cells[] = xs_text((string) $cell, 120);
        }
        if ($cells) $rows[] = $cells;
    }
    if (!$rows) return null;
    $cols = max(array_map('count', $rows));

    $hex = function ($v, $fallback) {
        $v = (string) $v;
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? $v : $fallback;
    };
    // One optional hex (or '' for "no override") per row/column, capped to the grid's actual size.
    $hexList = function ($list, $n) use ($hex) {
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = isset($list[$i]) && $list[$i] !== '' ? $hex($list[$i], '') : '';
        return $out;
    };

    return json_encode([
        'enabled' => !empty($p['tableEnabled']),
        'headerRow' => !empty($p['tableHeaderRow']),
        'hAlign' => xs_enum($p['tableHAlign'] ?? '', ['left', 'center', 'right'], 'center'),
        'vAlign' => xs_enum($p['tableVAlign'] ?? '', ['top', 'center', 'bottom'], 'center'),
        'width' => max(0, min(100, (int) ($p['tableWidth'] ?? 0))), // 0 = size to fit the content, like today
        'fontSize' => max(10, min(200, (int) ($p['tableFontSize'] ?? 16))),
        'headerFontSize' => max(10, min(200, (int) ($p['tableHeaderFontSize'] ?? $p['tableFontSize'] ?? 16))),
        'textColor' => $hex($p['tableTextColor'] ?? '', '#ffffff'),
        'headerColor' => $hex($p['tableHeaderColor'] ?? '', '#ff2d8f'),
        'borderColor' => $hex($p['tableBorderColor'] ?? '', '#ffffff'),
        'borderWidth' => max(0, min(8, (int) ($p['tableBorderWidth'] ?? 1))),
        'bgOpacity' => round(max(0, min(1, (float) ($p['tableBgOpacity'] ?? 0.55))), 2),
        'rows' => $rows,
        'rowColors' => $hexList($grid['rowColors'] ?? [], count($rows)),
        'colColors' => $hexList($grid['colColors'] ?? [], $cols),
    ]);
}

function xs_frame_from_post(array $p, $id)
{
    $f = ['id' => $id];
    foreach (['image', 'mobileImage'] as $k) {
        $v = xs_url($p[$k] ?? '');
        if ($v !== '') $f[$k] = $v;
    }
    foreach (['alt' => 200, 'eyebrow' => 80, 'title' => 200, 'description' => 600, 'buttonText' => 60] as $k => $max) {
        $v = xs_text($p[$k] ?? '', $max);
        if ($v !== '') $f[$k] = $v;
    }
    $url = xs_url($p['buttonUrl'] ?? '');
    if ($url !== '') $f['buttonUrl'] = $url;
    $f['openInNewTab'] = !empty($p['openInNewTab']);

    $f['textAlign'] = xs_enum($p['textAlign'] ?? '', ['left', 'center', 'right'], 'left');
    $f['verticalAlign'] = xs_enum($p['verticalAlign'] ?? '', ['top', 'center', 'bottom'], 'center');
    $f['offsetX'] = xs_css($p['offsetX'] ?? '') ?: '0px';
    $f['offsetY'] = xs_css($p['offsetY'] ?? '') ?: '0px';

    $f['overlay'] = !empty($p['overlay']);
    $f['overlayStrength'] = round(max(0, min(1, (float) ($p['overlayStrength'] ?? 0.55))), 2);
    $f['overlayDirection'] = xs_enum($p['overlayDirection'] ?? '', ['auto', 'left', 'right', 'bottom', 'full'], 'auto');

    $f['focalPoint'] = xs_css($p['focalPoint'] ?? '') ?: '50% 50%';
    $mf = xs_css($p['mobileFocalPoint'] ?? '');
    if ($mf !== '') $f['mobileFocalPoint'] = $mf;
    $f['kenBurns'] = !empty($p['kenBurns']);

    $color = (string) ($p['textColor'] ?? '');
    $f['textColor'] = preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#ffffff';
    foreach (['fontFamily' => 120, 'titleSize' => 80] as $k => $max) {
        $v = xs_css($p[$k] ?? '', $max);
        if ($v !== '') $f[$k] = $v;
    }
    $w = (int) ($p['titleWeight'] ?? 0);
    if (in_array($w, [400, 500, 600], true)) $f['titleWeight'] = $w;

    $t = xs_enum($p['transition'] ?? '', ['fade', 'zoom', 'drift'], '');
    if ($t !== '') $f['transition'] = $t;
    $d = trim((string) ($p['duration'] ?? ''));
    if ($d !== '' && is_numeric($d)) $f['duration'] = (int) round(max(1, min(60, (float) $d)) * 1000);

    $tableJson = xs_table_from_post($p);
    if ($tableJson !== null) $f['tableData'] = $tableJson;

    return $f;
}

// =====================================================================
//  State
// =====================================================================

$dbOk = xs_db() !== null;

// Company-based multi-user auth: a user belongs to exactly one company (see schema.sql /
// migrate_to_companies.sql). $companyId below is the ONLY value ever used to scope frames/
// settings/tags queries, and it only ever comes from the trusted server-side session — never
// from a request parameter — so one company can never read or change another's data.
$authed = !empty($_SESSION['xs_auth']) && !empty($_SESSION['xs_user_id']) && !empty($_SESSION['xs_company_id']);

// 'user' role accounts expire 1 year after creation; admins never do (null expires_at —
// see xs_expiry_for_role()). Checked at login (below) and again here on every single
// request — so a session that's simply been open since before the expiry date gets signed
// out the moment it crosses it, not just at the next login.
if ($authed && !empty($_SESSION['xs_expires_at']) && strtotime($_SESSION['xs_expires_at']) <= time()) {
    xs_db_expire_user((int) $_SESSION['xs_user_id']);
    $_SESSION = [];
    $_SESSION['flash'] = ['error', 'Your account has expired. Contact your admin.'];
    session_regenerate_id(true);
    $authed = false;
}

$companyId = $authed ? (int) $_SESSION['xs_company_id'] : null;
$userRole = $authed ? (string) ($_SESSION['xs_role'] ?? 'user') : null;
$canManageUsers = $userRole === 'admin';

$frames = $authed ? xs_db_list_frames($companyId) : []; // id uniqueness is enforced by the table's primary key (per company)
$tags = $authed ? xs_db_list_tags($companyId) : []; // {{name}} placeholders usable in a frame's table cells

$dbSettings = $authed ? xs_db_get_settings($companyId) : null; // ['settings' => [...]] or null
$settings = array_merge($slider, ['pageTitle' => $pageTitle]);
if ($dbSettings) $settings = array_merge($settings, $dbSettings['settings']);

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// =====================================================================
//  Actions (POST → save → redirect)
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = (string) ($_POST['back'] ?? '');
    if (!preg_match('/^[a-z0-9=&_-]*$/i', $back)) $back = '';

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        xs_redirect($back, 'That upload was larger than this server allows (' . ini_get('post_max_size') . '). Try a smaller image.', 'error');
    }
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        xs_redirect($back, 'Your session expired. Please try again.', 'error');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!$authed) {
            if ($action === 'register') {
                $companyName = xs_text($_POST['companyName'] ?? '', 120);
                $username = xs_text($_POST['username'] ?? '', 80);
                $email = xs_text($_POST['email'] ?? '', 190);
                $pw = (string) ($_POST['password'] ?? '');
                if ($companyName === '') xs_redirect('', 'Enter your company name.', 'error');
                if ($username === '') xs_redirect('', 'Choose a username.', 'error');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) xs_redirect('', 'Enter a valid email address.', 'error');
                if (strlen($pw) < 8) xs_redirect('', 'Use at least 8 characters.', 'error');
                if ($pw !== (string) ($_POST['confirm'] ?? '')) xs_redirect('', 'The two passwords do not match.', 'error');
                if (!$dbOk) xs_redirect('', 'Could not reach the database.', 'error');
                $reg = xs_db_register_company($companyName, $username, password_hash($pw, PASSWORD_DEFAULT), $email !== '' ? $email : null);
                $newUser = xs_db_find_user_by_id($reg['userId']);
                session_regenerate_id(true);
                $_SESSION['xs_auth'] = true;
                $_SESSION['xs_user_id'] = $reg['userId'];
                $_SESSION['xs_company_id'] = $reg['companyId'];
                $_SESSION['xs_role'] = 'admin';
                $_SESSION['xs_expires_at'] = $newUser['expires_at'];
                xs_redirect('', 'Company registered. Welcome to your slider admin.');
            }
            if ($action === 'login') {
                $username = xs_text($_POST['username'] ?? '', 80);
                $user = $username !== '' ? xs_db_find_user_by_username($username) : null;
                if ($user && $user['status'] === 'active' && $user['payment_status'] !== 'pending' && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                    // This panel is for 'admin' accounts only — a 'user' account (created for the
                    // NexShow device/client login) must use that screen instead, never this one.
                    if ($user['role'] !== 'admin') {
                        sleep(1);
                        xs_redirect('', 'This is a user account — sign in from the NexShow device screen instead.', 'error');
                    }
                    if (xs_user_is_expired($user)) {
                        xs_db_expire_user((int) $user['id']);
                        xs_redirect('', 'That account has expired. Contact your admin.', 'error');
                    }
                    session_regenerate_id(true);
                    $_SESSION['xs_auth'] = true;
                    $_SESSION['xs_user_id'] = (int) $user['id'];
                    $_SESSION['xs_company_id'] = (int) $user['company_id'];
                    $_SESSION['xs_role'] = $user['role'];
                    $_SESSION['xs_expires_at'] = $user['expires_at'];
                    xs_redirect();
                }
                sleep(1);
                xs_redirect('', 'Wrong username or password.', 'error');
            }
            if ($action === 'forgot_password') {
                $username = xs_text($_POST['username'] ?? '', 80);
                $user = $username !== '' ? xs_db_find_user_by_username($username) : null;
                // Same message either way — confirming or denying that a username/email
                // exists would let an attacker enumerate accounts.
                if ($user && $user['status'] === 'active' && !empty($user['email'])) {
                    $token = bin2hex(random_bytes(32));
                    xs_db_set_reset_token((int) $user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 1800));
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['PHP_SELF'], '?') . '?view=reset&token=' . $token;
                    xs_send_mail(
                        $user['email'],
                        'Reset your NexShoW password',
                        "Someone asked to reset the password for the account \"{$user['username']}\".\n\n"
                            . "Reset it here (this link works for 30 minutes):\n$link\n\n"
                            . "If you didn't ask for this, you can safely ignore this email."
                    );
                }
                xs_redirect('view=login', 'If that account has an email on file, a reset link is on its way.');
            }
            if ($action === 'reset_password') {
                $token = (string) ($_POST['token'] ?? '');
                $user = $token !== '' ? xs_db_find_user_by_reset_token(hash('sha256', $token)) : null;
                if (!$user) xs_redirect('view=forgot', 'That reset link is invalid or has expired.', 'error');
                $pw = (string) ($_POST['password'] ?? '');
                $resetBack = 'view=reset&token=' . rawurlencode($token);
                if (strlen($pw) < 8) xs_redirect($resetBack, 'Use at least 8 characters.', 'error');
                if ($pw !== (string) ($_POST['confirm'] ?? '')) xs_redirect($resetBack, 'The two passwords do not match.', 'error');
                xs_db_update_user_password((int) $user['id'], password_hash($pw, PASSWORD_DEFAULT));
                xs_db_clear_reset_token((int) $user['id']);
                xs_redirect('view=login', 'Password reset. Log in with your new password.');
            }
            xs_redirect();
        }

        $id = (string) ($_POST['id'] ?? '');
        $idx = $id === '' ? -1 : xs_find($frames, $id);

        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_regenerate_id(true);
                xs_redirect('', 'You are logged out.');
                break;

            case 'save_frame':
                if (!$dbOk) xs_redirect($back, 'Could not reach the database. The frame was not saved.', 'error');
                if ($id !== '' && $idx < 0) xs_redirect('', 'That frame no longer exists.', 'error');
                $frame = xs_frame_from_post($_POST, $id !== '' ? $id : xs_new_id($_POST['title'] ?? '', $frames));
                foreach (['image' => true, 'mobileImage' => false] as $k => $allowVideo) {
                    $path = xs_upload($companyId, $k . '_file', $allowVideo);
                    if ($path !== null) $frame[$k] = $path;
                }
                if ($idx >= 0) {
                    // An image/mobileImage this frame managed (images/Frame/…) that's being removed or
                    // replaced is no longer referenced by this save — delete the old file so it doesn't
                    // just sit around unused. Never touches embedded/external paths (xs_is_frame_file).
                    foreach (['image', 'mobileImage'] as $k) {
                        $oldPath = (string) ($frames[$idx][$k] ?? '');
                        $newPath = (string) ($frame[$k] ?? '');
                        if ($oldPath !== '' && $oldPath !== $newPath && xs_is_frame_file($oldPath)) {
                            @unlink(__DIR__ . '/' . $oldPath);
                        }
                    }
                    xs_db_update_frame($companyId, $id, $frame);
                } else {
                    xs_db_insert_frame($companyId, $frame['id'], $frame, count($frames));
                }
                xs_redirect('view=edit&id=' . rawurlencode($frame['id']), $idx >= 0 ? 'Frame saved.' : 'Frame added.');
                break;

            case 'delete_frame':
                if (!$dbOk) xs_redirect($back, 'Could not reach the database. Nothing was deleted.', 'error');
                if ($idx >= 0) {
                    xs_delete_frame_files($frames[$idx]); // image files first; the row is what makes them "used"
                    xs_db_delete_frame($companyId, $id);
                }
                xs_redirect('', 'Frame deleted.');
                break;

            case 'move':
                if (!$dbOk) xs_redirect($back, 'Could not reach the database.', 'error');
                $to = $idx + ((int) ($_POST['dir'] ?? 0) < 0 ? -1 : 1);
                if ($idx >= 0 && $to >= 0 && $to < count($frames)) {
                    $tmp = $frames[$idx];
                    $frames[$idx] = $frames[$to];
                    $frames[$to] = $tmp;
                    xs_db_renumber($companyId, array_column($frames, 'id'));
                }
                xs_redirect();
                break;

            case 'duplicate':
                if (!$dbOk) xs_redirect($back, 'Could not reach the database.', 'error');
                if ($idx >= 0) {
                    $copy = $frames[$idx];
                    $copy['title'] = trim(($copy['title'] ?? '') . ' (copy)');
                    $copy['id'] = xs_new_id($copy['title'], $frames);
                    array_splice($frames, $idx + 1, 0, [$copy]);
                    xs_db_insert_frame($companyId, $copy['id'], $copy, $idx + 1);
                    xs_db_renumber($companyId, array_column($frames, 'id'));
                }
                xs_redirect('', 'Frame duplicated.');
                break;

            case 'save_settings':
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database. Settings were not saved.', 'error');
                $p = $_POST;
                $s = [];
                $s['pageTitle'] = xs_text($p['pageTitle'] ?? '', 80) ?: 'NexShoW';
                $s['ariaLabel'] = xs_text($p['ariaLabel'] ?? '', 80) ?: 'Slideshow';
                $s['autoplay'] = !empty($p['autoplay']);
                $s['pauseOnHover'] = !empty($p['pauseOnHover']);
                $s['duration'] = (int) round(max(1, min(60, (float) ($p['duration'] ?? 5))) * 1000);
                $s['transition'] = xs_enum($p['transition'] ?? '', ['fade', 'zoom', 'drift'], 'fade');
                $s['transitionMs'] = max(100, min(5000, (int) ($p['transitionMs'] ?? 1200)));
                $s['swipeThreshold'] = max(10, min(400, (int) ($p['swipeThreshold'] ?? 50)));
                $s['mobileBreakpoint'] = max(320, min(1600, (int) ($p['mobileBreakpoint'] ?? 768)));
                xs_db_save_settings($companyId, $s);
                xs_redirect('view=settings', 'Settings saved.');
                break;

            case 'save_email':
                $email = xs_text($_POST['email'] ?? '', 190);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) xs_redirect('view=settings', 'Enter a valid email address.', 'error');
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                xs_db_update_user_email((int) $_SESSION['xs_user_id'], $email !== '' ? $email : null);
                xs_redirect('view=settings', 'Email saved.');
                break;

            case 'change_password':
                $me = xs_db_find_user_by_id((int) $_SESSION['xs_user_id']);
                if (!$me || !password_verify((string) ($_POST['current'] ?? ''), $me['password_hash'])) {
                    xs_redirect('view=settings', 'Your current password is not right.', 'error');
                }
                $pw = (string) ($_POST['password'] ?? '');
                if (strlen($pw) < 8) xs_redirect('view=settings', 'Use at least 8 characters.', 'error');
                if ($pw !== (string) ($_POST['confirm'] ?? '')) xs_redirect('view=settings', 'The two new passwords do not match.', 'error');
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                xs_db_update_user_password((int) $_SESSION['xs_user_id'], password_hash($pw, PASSWORD_DEFAULT));
                xs_redirect('view=settings', 'Password changed.');
                break;

            case 'add_user':
                if (!$canManageUsers) xs_redirect('view=settings', 'Only an admin can add users.', 'error');
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                $username = xs_text($_POST['username'] ?? '', 80);
                $pw = (string) ($_POST['password'] ?? '');
                $role = xs_enum($_POST['role'] ?? '', ['admin', 'user'], 'user');
                if ($username === '') xs_redirect('view=settings', 'Choose a username.', 'error');
                if (strlen($pw) < 8) xs_redirect('view=settings', 'Use at least 8 characters.', 'error');
                $isTrial = xs_db_create_user($companyId, $username, password_hash($pw, PASSWORD_DEFAULT), $role);
                if ($isTrial) {
                    xs_redirect('view=settings', 'User added — free for 3 days. A payment will be needed to keep it active after that.');
                }
                xs_redirect('view=settings&activate=' . rawurlencode($username), 'User added. Make a payment to activate it.');
                break;

            case 'toggle_user':
                if (!$canManageUsers) xs_redirect('view=settings', 'Only an admin can manage users.', 'error');
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                $targetId = (int) ($_POST['userId'] ?? 0);
                if ($targetId === (int) $_SESSION['xs_user_id']) xs_redirect('view=settings', "You can't deactivate your own account.", 'error');
                $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
                $target = xs_db_find_company_user($companyId, $targetId);
                // A user whose expiry has already passed is treated as needing payment again,
                // same as if it had already been auto-expired — it just hasn't been yet, because
                // nothing has logged in or had an open session poll since to trigger that. Self-
                // heal it right here rather than letting a stale payment_status of 'paid' let it
                // through: xs_db_expire_user() resets payment_status to 'none', so the normal
                // "needs payment" guard right after then correctly blocks it.
                if ($status === 'active' && $target && xs_user_is_expired($target)) {
                    xs_db_expire_user($targetId);
                    $target['payment_status'] = 'none';
                }
                // Only a user whose fee is actually marked 'paid' can be switched back on from
                // here — 'pending' (awaiting confirmation) or 'none' (never paid, or expired and
                // reset back to needing a fresh payment) both require going through the payment
                // flow again instead; only Ab Studio Digital can mark that 'paid' directly in
                // the database.
                if ($status === 'active' && $target && $target['payment_status'] !== 'paid') {
                    xs_redirect('view=settings', 'This user needs a payment before it can be activated.', 'error');
                }
                if ($status === 'inactive' && xs_db_count_active_admins($companyId) <= 1) {
                    if ($target && $target['role'] === 'admin') {
                        xs_redirect('view=settings', "Can't deactivate the only admin — promote someone else first.", 'error');
                    }
                }
                xs_db_set_user_status($companyId, $targetId, $status);
                xs_redirect('view=settings', $status === 'active' ? 'User reactivated.' : 'User deactivated.');
                break;

            case 'reset_user_password':
                if (!$canManageUsers) xs_redirect('view=settings', 'Only an admin can reset passwords.', 'error');
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                $targetId = (int) ($_POST['userId'] ?? 0);
                $pw = (string) ($_POST['password'] ?? '');
                if (strlen($pw) < 8) xs_redirect('view=settings', 'Use at least 8 characters.', 'error');
                // Looked up scoped to this admin's own company — a userId guessed/tampered to
                // point at another company's user must never get an email out of this.
                $target = xs_db_find_company_user($companyId, $targetId);
                if (!$target) xs_redirect('view=settings', 'That user was not found.', 'error');
                xs_db_reset_user_password($companyId, $targetId, password_hash($pw, PASSWORD_DEFAULT));
                if (!empty($target['email'])) {
                    xs_send_mail(
                        $target['email'],
                        'Your NexShoW password was reset',
                        "An admin reset the password for your account \"{$target['username']}\".\n\n"
                            . "Your new password is:\n$pw\n\n"
                            . "Sign in with it, then change it yourself from Settings if you'd like."
                    );
                    xs_redirect('view=settings', 'Password reset. An email was sent to ' . $target['username'] . '.');
                }
                xs_redirect('view=settings', 'Password reset. No email is on file for ' . $target['username'] . ', so let them know the new password yourself.');
                break;

            case 'cleanup_uploads':
                $used = xs_used_images($frames);
                $n = 0;
                foreach (xs_frame_files($companyId) as $path) {
                    if (!isset($used[$path]) && @unlink(__DIR__ . '/' . $path)) $n++;
                }
                xs_redirect('view=settings', $n ? "Deleted $n unused file" . ($n === 1 ? '' : 's') . '.' : 'There were no unused files.');
                break;

            case 'save_tag':
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                $tagName = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['tagName'] ?? '')));
                $tagName = substr($tagName, 0, 64);
                if ($tagName === '') xs_redirect('view=settings', 'Give the tag a name using only letters, numbers and underscores.', 'error');
                $tagValue = xs_text($_POST['tagValue'] ?? '', 255);
                xs_db_save_tag($companyId, $tagName, $tagValue);
                xs_redirect('view=settings', 'Tag saved. Use it as {{' . $tagName . '}} in a table cell.');
                break;

            case 'delete_tag':
                if (!$dbOk) xs_redirect('view=settings', 'Could not reach the database.', 'error');
                $tagName = (string) ($_POST['tagName'] ?? '');
                if ($tagName !== '') xs_db_delete_tag($companyId, $tagName);
                xs_redirect('view=settings', 'Tag deleted.');
                break;
        }
        xs_redirect();
    } catch (RuntimeException $ex) {
        xs_redirect($back, $ex->getMessage(), 'error');
    }
}

// =====================================================================
//  View
// =====================================================================

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Logged out: plain admin.php always defaults to "login", even before anyone has ever
// registered — ?view=register is reachable via the link on that screen (or directly) for
// a new company to sign up.
if (!$authed) {
    $view = (string) ($_GET['view'] ?? '');
    if (!in_array($view, ['register', 'login', 'forgot', 'reset'], true)) $view = 'login';
    // A reset link is only shown to type a new password into once we know its token is
    // still valid — otherwise send them back to ask for a fresh one.
    if ($view === 'reset') {
        $resetToken = (string) ($_GET['token'] ?? '');
        if ($resetToken === '' || !xs_db_find_user_by_reset_token(hash('sha256', $resetToken))) {
            xs_redirect('view=forgot', 'That reset link is invalid or has expired.', 'error');
        }
    }
} else {
    $view = (string) ($_GET['view'] ?? 'frames');
    if (!in_array($view, ['frames', 'edit', 'settings'], true)) $view = 'frames';
}

$defaultSeconds = $settings['duration'] / 1000;

if ($view === 'edit') {
    $editId = (string) ($_GET['id'] ?? '');
    $editIdx = $editId === '' ? -1 : xs_find($frames, $editId);
    if ($editId !== '' && $editIdx < 0) xs_redirect('', 'That frame no longer exists.', 'error');
    $raw = $editIdx >= 0 ? $frames[$editIdx] : [];
    $f = array_merge($slideDefaults, $raw);
    $table = !empty($raw['tableData']) ? json_decode($raw['tableData'], true) : null;
    if (!is_array($table)) $table = [];
    $tableGrid = [
        'rows' => !empty($table['rows']) && is_array($table['rows']) ? $table['rows'] : [['', ''], ['', '']],
        'rowColors' => is_array($table['rowColors'] ?? null) ? $table['rowColors'] : [],
        'colColors' => is_array($table['colColors'] ?? null) ? $table['colColors'] : [],
    ];
}

// Image library: photos uploaded to images/Frame.
$library = [];
if ($authed) {
    $used = xs_used_images($frames);
    foreach (xs_frame_files($companyId) as $path) $library[] = ['value' => $path, 'url' => $path, 'label' => basename($path), 'used' => isset($used[$path])];
}

function xs_seg($name, array $options, $value)
{
    echo '<div class="seg" role="radiogroup">';
    foreach ($options as $v => $label) {
        echo '<label><input type="radio" name="' . e($name) . '" value="' . e($v) . '"' . ($v === $value ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    echo '</div>';
}

function xs_select($name, array $options, $value)
{
    $value = (string) $value;
    if ($value !== '' && !array_key_exists($value, $options)) $options = [$value => 'Custom: ' . $value] + $options;
    echo '<select name="' . e($name) . '">';
    foreach ($options as $v => $label) {
        echo '<option value="' . e($v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
}

function xs_image_field($name, $label, $hint, $value, $focalName, $focalValue, $allowVideo = false)
{
    $url = xs_thumb($value);
    $isVideo = xs_is_video_path($value);
    $accept = 'image/jpeg,image/png,image/webp,image/gif' . ($allowVideo ? ',video/mp4,video/webm,video/quicktime' : '');
    ?>
    <div class="imgfield" data-imgfield<?= $allowVideo ? ' data-allow-video' : '' ?>>
      <div class="imgfield__head"><span class="label"><?= e($label) ?></span><span class="hint"><?= e($hint) ?></span></div>
      <div class="imgfield__stage<?= $url === '' ? ' is-empty' : '' ?>" data-stage>
        <div class="imgfield__pic">
          <img alt="" data-img<?= $url !== '' && !$isVideo ? ' src="' . e($url) . '"' : '' ?><?= $isVideo ? ' hidden' : '' ?>>
          <video data-vid muted loop playsinline<?= $url !== '' && $isVideo ? ' src="' . e($url) . '"' : ''  ?><?= $isVideo ? '' : ' hidden' ?>></video>
        </div>
        <p class="imgfield__empty">No image</p>
      </div>
      <div class="imgfield__actions">
        <label class="btn btn--sm">Upload<input type="file" class="visually-hidden" name="<?= e($name) ?>_file" accept="<?= e($accept) ?>" data-file></label>
        <button type="button" class="btn btn--sm" data-open-library data-library-kind="<?= $allowVideo ? 'media' : 'image' ?>">Library</button>
        <button type="button" class="btn btn--sm btn--ghost" data-clear>Remove</button>
      </div>
      <label class="field"><span>Path or URL</span><input type="text" name="<?= e($name) ?>" value="<?= e($value) ?>" data-path spellcheck="false"></label>
      <input type="hidden" name="<?= e($focalName) ?>" value="<?= e($focalValue) ?>">
    </div>
<?php }

$titles =['register' => 'Register', 'login' => 'Log in', 'forgot' => 'Forgot password', 'reset' => 'Reset password', 'frames' => 'Frames', 'edit' => 'Edit frame', 'settings' => 'Settings'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script>
// Applied before the stylesheet paints, so there's no flash of the wrong theme.
try {
  if (localStorage.getItem('xshow-admin-theme') === 'light') document.documentElement.setAttribute('data-theme', 'light');
} catch (e) {}
</script>
<title><?= e($titles[$view]) ?> · <?= e($settings['pageTitle']) ?> admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  color-scheme: dark;
  --bg: #0d0c0b;
  --panel: #161513;
  --panel-2: #1e1c19;
  --line: rgba(255, 255, 255, 0.09);
  --line-2: rgba(255, 255, 255, 0.16);
  --text: #f2efe9;
  --muted: #a39e95;
  --accent: #ff2d8f;
  --accent-ink: #1a0310;
  --btn-primary: #ff2d8f;
  --btn-primary-hover: #ff5fab;
  --btn-primary-ink: #ffffff;
  --danger: #e8806f;
  --ok: #94cf9f;
  --radius: 12px;
  --chrome-bg: rgba(13, 12, 11, 0.86);
  --chrome-bg-strong: rgba(13, 12, 11, 0.9);
  --checker-a: #1b1a18;
  --checker-b: #151412;
  --auth-grad: radial-gradient(90% 70% at 70% 20%, #2a241b 0%, #0d0c0b 60%);
  --font-ui: 'Instrument Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  --font-display: var(--font-ui); /* admin headings match the body text */
}
:root[data-theme="light"] {
  color-scheme: light;
  --bg: #f7f5f1;
  --panel: #ffffff;
  --panel-2: #f0eee8;
  --line: rgba(0, 0, 0, 0.08);
  --line-2: rgba(0, 0, 0, 0.14);
  --text: #211f1a;
  --muted: #6e6a62;
  --chrome-bg: rgba(255, 255, 255, 0.86);
  --chrome-bg-strong: rgba(255, 255, 255, 0.9);
  --checker-a: #e9e7e1;
  --checker-b: #f8f6f2;
  --auth-grad: radial-gradient(90% 70% at 70% 20%, #fdf6e8 0%, #f7f5f1 60%);
}
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font: 15px/1.5 var(--font-ui);
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; }
button, input, select, textarea { font: inherit; color: inherit; }
h1, h2, h3 { margin: 0; font-weight: 400; }

/* ---------- Top bar ---------- */
.top {
  position: sticky; top: 0; z-index: 20;
  display: flex; align-items: center; gap: 1.5rem;
  padding: 0 clamp(16px, 3vw, 32px); height: 60px;
  background: var(--chrome-bg);
  backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--line);
}
.brand { font-family: var(--font-display); font-size: 1.9rem; text-decoration: none; letter-spacing: -0.01em; }
.brand::first-letter { color: var(--accent); font-size: 1.25em; }
.brand small { font-family: var(--font-ui); font-size: 0.75rem; color: var(--accent); margin-left: 0.4rem; letter-spacing: 0.04em; }
.nav { display: flex; gap: 0.6rem; margin-right: auto; }
.nav a {
  padding: 0.7rem 1.5rem; border: 1px solid var(--line-2); border-radius: 10px;
  color: var(--muted); font-size: 1rem; font-weight: 500; text-decoration: none;
  transition: background-color .2s, border-color .2s, color .2s;
}
.nav a:hover { color: var(--text); background: var(--panel); border-color: rgba(255, 255, 255, 0.26); }
.nav a[aria-current] { color: var(--text); background: var(--panel-2); border-color: var(--line-2); }
.top__right { display: flex; align-items: center; gap: 0.5rem; }
.top form { margin: 0; }

.wrap { max-width: 1240px; margin: 0 auto; padding: clamp(20px, 4vw, 40px) clamp(16px, 3vw, 32px) 120px; }
.page-head { display: flex; align-items: end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.75rem; }
.page-head h1 { font-family: var(--font-display); font-size: clamp(2rem, 4vw, 2.75rem); line-height: 1.05; letter-spacing: -0.015em; }
.page-head p { margin: 0.35rem 0 0; color: var(--muted); }
.back { display: inline-block; margin-bottom: 0.6rem; color: var(--muted); text-decoration: none; font-size: 0.875rem; }
.back:hover { color: var(--text); }

/* ---------- Buttons ---------- */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
  min-height: 40px; padding: 0.5rem 1rem;
  background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 999px;
  text-decoration: none; cursor: pointer; white-space: nowrap;
  transition: background-color .2s, border-color .2s, color .2s;
}
.btn:hover { background: #2a2723; border-color: rgba(255, 255, 255, 0.26); }
.btn--primary { background: var(--btn-primary); border-color: var(--btn-primary); color: var(--btn-primary-ink); font-weight: 600; }
.btn--primary:hover { background: var(--btn-primary-hover); border-color: var(--btn-primary-hover); }
.btn--ghost { background: transparent; border-color: transparent; color: var(--muted); }
.btn--ghost:hover { background: var(--panel-2); color: var(--text); border-color: transparent; }
.btn--danger { color: var(--danger); }
.btn--danger:hover { background: rgba(232, 128, 111, 0.12); border-color: rgba(232, 128, 111, 0.4); }
.btn--success { background: #22a855; border-color: #22a855; color: #fff; font-weight: 600; }
.btn--success:hover { background: #1c8f47; border-color: #1c8f47; }
.theme-field { display: flex; align-items: center; gap: 0.5rem; }
.theme-field__label { font-size: 0.8125rem; color: var(--muted); white-space: nowrap; }
.theme-switch {
  position: relative; display: inline-flex; align-items: center; flex-shrink: 0;
  width: 46px; height: 26px; padding: 3px;
  background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 999px;
  cursor: pointer;
  transition: background-color .25s ease, border-color .25s ease;
}
.theme-switch[aria-checked="true"] { background: var(--accent); border-color: var(--accent); }
.theme-switch__thumb {
  width: 18px; height: 18px; border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
  transition: transform .25s cubic-bezier(0.2, 0.8, 0.2, 1);
}
.theme-switch[aria-checked="true"] .theme-switch__thumb { transform: translateX(20px); }
.btn--sm { min-height: 34px; padding: 0.3rem 0.8rem; font-size: 0.875rem; }
.btn--icon { width: 34px; min-height: 34px; padding: 0; }
.btn[disabled] { opacity: 0.35; pointer-events: none; }
.btn:focus-visible, .seg input:focus-visible + span, input:focus-visible, select:focus-visible, textarea:focus-visible, .nav a:focus-visible {
  outline: none;
}
.btn:focus:not(.btn--danger):not(.btn--ghost), .btn:hover:not(.btn--danger):not(.btn--ghost) {
  background: var(--btn-primary);
  border-color: var(--btn-primary);
  color: var(--btn-primary-ink);
  outline: none;
}

/* ---------- Flash ---------- */
.flash {
  display: flex; align-items: center; gap: 0.6rem;
  margin: 0 0 1.5rem; padding: 0.8rem 1rem;
  border-radius: 10px; border: 1px solid; font-size: 0.9375rem;
}
.flash--ok { color: var(--ok); background: rgba(148, 207, 159, 0.08); border-color: rgba(148, 207, 159, 0.25); }
.flash--error { color: var(--danger); background: rgba(232, 128, 111, 0.08); border-color: rgba(232, 128, 111, 0.3); }

/* ---------- Frames grid ---------- */
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 1.25rem; }
.card { display: flex; flex-direction: column; background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
.card__thumb {
  position: relative; display: block; aspect-ratio: 16 / 10; overflow: hidden;
  background: radial-gradient(120% 90% at 70% 40%, #3a3127 0%, #15120f 55%, #000 100%);
  text-decoration: none; color: #fff;
}
.card__thumb img, .card__thumb video { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .6s cubic-bezier(.2, .7, .2, 1); }
.card__thumb:hover img, .card__thumb:hover video { transform: scale(1.04); }
.card__thumb::after { content: ''; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(0, 0, 0, .7), rgba(0, 0, 0, 0) 65%); }
.card__num {
  position: absolute; top: 0.7rem; left: 0.7rem; z-index: 1;
  min-width: 28px; height: 28px; padding: 0 0.5rem; display: grid; place-items: center;
  background: rgba(0, 0, 0, 0.55); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 999px;
  font-size: 0.8125rem; font-weight: 600; backdrop-filter: blur(4px);
}
.card__caption { position: absolute; left: 1rem; right: 1rem; bottom: 0.85rem; z-index: 1; }
.card__eyebrow { margin: 0 0 0.2rem; font-size: 0.75rem; color: var(--accent); font-weight: 500; }
.card__title { font-family: var(--font-display); font-size: 1.35rem; line-height: 1.05; letter-spacing: -0.01em; text-wrap: balance; }
.card__body { display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem; border-top: 1px solid var(--line); }
.card__meta { margin-right: auto; padding-left: 0.25rem; font-size: 0.8125rem; color: var(--muted); }
.card__body form { margin: 0; }
.card--add {
  display: grid; place-items: center; min-height: 240px;
  background: transparent; border: 1px dashed var(--line-2);
  color: var(--muted); text-decoration: none; transition: border-color .2s, color .2s, background-color .2s;
}
.card--add:hover { border-color: var(--accent); color: var(--accent); background: rgba(255, 45, 143, 0.06); }
.card--add span { display: grid; justify-items: center; gap: 0.5rem; }
.card--add b { font-size: 2rem; font-weight: 300; line-height: 1; }

/* ---------- Forms ---------- */
.panel { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: clamp(16px, 2.5vw, 24px); }
.panel + .panel { margin-top: 1rem; }
.panel h2 { font-size: 1rem; font-weight: 600; margin-bottom: 1rem; }
.panel h2 small { font-weight: 400; color: var(--muted); margin-left: 0.4rem; font-size: 0.875rem; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 1rem; }
.row + .row, .row + .field, .field + .row, .field + .field, .check + .row, .row + .check { margin-top: 1rem; }
.field { display: grid; gap: 0.35rem; min-width: 0; }
.field > span, .label { font-size: 0.8125rem; font-weight: 500; color: var(--muted); }
.field > span em { font-style: normal; font-weight: 400; opacity: 0.75; margin-left: 0.3rem; }
input[type=text], input[type=password], input[type=email], input[type=number], input[type=url], select, textarea {
  width: 100%; min-height: 42px; padding: 0.55rem 0.75rem;
  background: var(--bg); border: 1px solid var(--line-2); border-radius: 8px;
  transition: border-color .2s;
}
input:hover, select:hover, textarea:hover { border-color: rgba(255, 255, 255, 0.26); }
.pw-wrap { position: relative; display: block; }
.pw-wrap input[type=password], .pw-wrap input[type=text] { padding-right: 3.75rem; }
.pw-toggle {
  position: absolute; top: 50%; right: 0.5rem; transform: translateY(-50%);
  min-height: 0; height: 28px; padding: 0 0.6rem;
  background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 999px;
  color: var(--muted); font-size: 0.75rem; font-weight: 500; cursor: pointer;
}
.pw-toggle:hover { color: var(--text); border-color: rgba(255, 255, 255, 0.26); }
textarea { min-height: 88px; resize: vertical; line-height: 1.5; }
input[type=color] { width: 100%; height: 42px; padding: 4px; background: var(--bg); border: 1px solid var(--line-2); border-radius: 8px; cursor: pointer; }
input[type=range] { width: 100%; accent-color: var(--accent); }
.check { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; }
.check input { width: 18px; height: 18px; accent-color: var(--accent); margin: 0; }
.range-row {
  display: flex; align-items: center; gap: 0.75rem; min-height: 42px;
  padding: 0 0.75rem; background: var(--bg); border: 1px solid var(--line-2); border-radius: 8px;
  transition: border-color .2s;
}
.range-row:hover { border-color: rgba(255, 255, 255, 0.26); }
.range-row output { min-width: 3ch; font-variant-numeric: tabular-nums; color: var(--muted); }

.seg { display: flex; padding: 3px; background: var(--bg); border: 1px solid var(--line-2); border-radius: 10px; }
.seg label { flex: 1; }
.seg input { position: absolute; opacity: 0; pointer-events: none; }
.seg span { display: block; padding: 0.45rem 0.5rem; text-align: center; border-radius: 7px; cursor: pointer; color: var(--muted); font-size: 0.875rem; transition: background-color .2s, color .2s; }
.seg span:hover { color: var(--text); }
.seg input:checked + span { background: var(--panel-2); color: var(--text); box-shadow: inset 0 0 0 1px var(--line-2); }

/* ---------- Table editor ---------- */
.table-editor { margin-top: 1rem; }
.table-editor__grid { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
.table-editor__grid table { border-collapse: collapse; width: 100%; }
.table-editor__grid td { padding: 2px; border: 1px solid var(--line); vertical-align: middle; }
.table-editor__grid input {
  width: 100%; min-width: 90px; min-height: 36px; padding: 0.4rem 0.6rem;
  background: var(--bg); border: 0; border-radius: 6px; font-size: 0.875rem;
}
.table-editor__grid input:focus-visible { background: var(--panel-2); }
.table-editor__grid tr.is-header-row input {
  background: rgba(255, 45, 143, 0.1); color: var(--accent); font-weight: 600;
}
.table-editor__col-remove, .table-editor__row-remove {
  display: inline-grid; place-items: center; vertical-align: middle; width: 34px; height: 34px;
  padding: 0; margin-right: 0.35rem; background: none; border: 0; border-radius: 50%; color: var(--muted); cursor: pointer; font-size: 1.25rem; line-height: 1;
}
.table-editor__col-remove:hover, .table-editor__row-remove:hover { background: var(--panel-2); color: var(--danger); }
.table-editor__corner { background: var(--panel-2); width: 36px; }
.table-editor__head-cell {
  /* Stays a real table-cell (not flex) so column head-cells sit side by side instead of each one block-ifying onto its own row; its contents (remove button + swatch) are centered inline instead. */
  background: var(--panel-2); white-space: nowrap; padding: 2px 6px; text-align: center;
}
.table-editor__swatch-wrap { display: inline-flex; align-items: center; vertical-align: middle; gap: 2px; flex: none; }
input.table-editor__swatch {
  width: 24px; min-width: 24px; height: 24px; min-height: 24px; padding: 0; flex: none;
  background: none; border: 1px solid var(--line-2); border-radius: 50%; cursor: pointer; overflow: hidden;
}
input.table-editor__swatch::-webkit-color-swatch-wrapper { padding: 0; }
input.table-editor__swatch::-webkit-color-swatch { border: 0; border-radius: 50%; }
.table-editor__swatch-clear {
  display: grid; place-items: center; width: 18px; height: 18px; padding: 0; flex: none;
  background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 0.8125rem; line-height: 1;
}
.table-editor__swatch-clear:hover { color: var(--danger); }
.table-editor__toolbar { display: flex; gap: 0.5rem; margin-top: 0.75rem; }
.tag-chip {
  display: inline-flex; align-items: center; margin: 0.2rem 0.3rem 0 0; padding: 0.2rem 0.55rem;
  background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 999px;
  color: var(--accent); font-size: 0.75rem; font-family: monospace; cursor: pointer;
}
.tag-chip:hover { border-color: var(--accent); }
.tag-row { display: flex; align-items: end; gap: 0.5rem; }
.tag-row form:first-child { flex: 1; display: flex; align-items: end; gap: 0.5rem; }
.tag-row .field { flex: 1; min-width: 0; }
.user-block { padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--line); }
.user-block__reset { margin-top: 0.6rem; }

/* ---------- Image field ---------- */
/* Visually hidden rather than display:none — some mobile browsers won't open the native file
   picker for a click on an input that's display:none, even via a wrapping <label>. */
.visually-hidden {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
.images { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 1.25rem; }
.imgfield { display: grid; gap: 0.75rem; align-content: start; }
.imgfield__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; min-height: 2.5rem; }
.imgfield__head .label { white-space: nowrap; }
.imgfield__head .hint { text-align: right; }
.hint { font-size: 0.8125rem; color: var(--muted); opacity: 0.75; }
.panel > .hint { margin: 0.75rem 0 0; }
.imgfield__stage {
  display: grid; place-items: center; height: 200px; padding: 10px;
  background: repeating-conic-gradient(var(--checker-a) 0 25%, var(--checker-b) 0 50%) 0 0 / 16px 16px;
  border: 1px solid var(--line); border-radius: 10px;
  overflow: hidden;
}
.imgfield__pic { position: relative; line-height: 0; max-width: 100%; max-height: 100%; }
.imgfield__pic img, .imgfield__pic video { display: block; max-width: 100%; max-height: 180px; border-radius: 4px; user-select: none; -webkit-user-drag: none; }
.imgfield__empty { display: none; margin: 0; color: var(--muted); font-size: 0.875rem; }
.imgfield__stage.is-empty .imgfield__pic { display: none; }
.imgfield__stage.is-empty .imgfield__empty { display: block; }
.imgfield__actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

/* ---------- Edit layout ---------- */
.edit { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 440px); gap: 1.5rem; align-items: start; }
.edit__side { position: sticky; top: 84px; display: grid; gap: 1rem; }
.preview { padding: 0; overflow: hidden; }
.preview__bar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.75rem 0.9rem; border-bottom: 1px solid var(--line); }
.preview__bar .label { color: var(--text); }
.preview__bar .seg { padding: 2px; }
.preview__bar .seg span { padding: 0.25rem 0.6rem; font-size: 0.8125rem; }
.preview__frame { position: relative; overflow: hidden; background: #000; transition: height .3s; }
.preview__frame iframe { position: absolute; top: 0; left: 0; border: 0; transform-origin: 0 0; background: #000; }
.preview__empty { display: grid; place-items: center; aspect-ratio: 16 / 10; padding: 1.5rem; text-align: center; color: var(--muted); font-size: 0.9375rem; }
.preview__note { margin: 0; padding: 0.65rem 0.9rem; font-size: 0.8125rem; color: var(--muted); border-top: 1px solid var(--line); }

.savebar {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 15;
  display: flex; justify-content: center;
  padding: 0.75rem clamp(16px, 3vw, 32px) calc(0.75rem + env(safe-area-inset-bottom, 0px));
  background: var(--chrome-bg-strong); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  border-top: 1px solid var(--line);
}
.savebar__inner { display: flex; align-items: center; gap: 0.75rem; width: 100%; max-width: 1176px; }
.savebar__status { margin-right: auto; color: var(--muted); font-size: 0.875rem; }
.savebar__status.is-dirty { color: var(--accent); }

.danger-zone { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.danger-zone p { margin: 0; color: var(--muted); font-size: 0.875rem; }

/* ---------- Library ---------- */
dialog {
  width: min(880px, calc(100vw - 32px)); max-height: min(80vh, 720px); padding: 0;
  background: var(--panel); color: var(--text);
  border: 1px solid var(--line-2); border-radius: 14px;
  box-shadow: 0 30px 80px rgba(0, 0, 0, .6);
}
dialog::backdrop { background: rgba(0, 0, 0, 0.65); backdrop-filter: blur(3px); }
.lib__head { position: sticky; top: 0; display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1.1rem; background: var(--panel); border-bottom: 1px solid var(--line); z-index: 1; }
.lib__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; padding: 1.1rem; }
.lib__item { display: grid; gap: 0.4rem; padding: 0; background: none; border: 0; text-align: left; cursor: pointer; }
.lib__item img, .lib__item video { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); transition: border-color .2s, transform .2s; background: #000; }
.lib__item:hover img, .lib__item:hover video { border-color: var(--accent); transform: translateY(-2px); }
.lib__item[hidden] { display: none; }
.lib__item span { font-size: 0.75rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lib__empty { padding: 2rem; text-align: center; color: var(--muted); }

/* ---------- Pay-to-activate modal ---------- */
#payModal { width: min(420px, calc(100vw - 32px)); max-height: min(90vh, 640px); padding: 2rem 1.75rem; text-align: center; overflow-y: auto; }
#payModal h2 { margin: 0 0 0.5rem; font-family: var(--font-display); font-size: 1.4rem; }
#payModal p { color: var(--muted); margin: 0 0 1rem; }
#payModal .field { text-align: left; }
.pay__icon { width: 56px; height: 56px; margin: 0 auto 1rem; display: grid; place-items: center; border-radius: 50%; background: rgba(255, 45, 143, 0.12); font-size: 1.6rem; }
.pay__actions { display: grid; gap: 0.6rem; margin-top: 1rem; }
.pay__actions .btn { width: 100%; }
.pay__qr { display: flex; justify-content: center; margin-bottom: 0.75rem; }
.pay__qr > div { width: 186px; height: 186px; background: #fff; border-radius: 10px; padding: 8px; display: flex; align-items: center; justify-content: center; }
.pay__upi strong { color: var(--text); }
.pay__error { color: #ff6b6b; font-size: 0.8125rem; margin: -0.5rem 0 0.75rem; }

/* ---------- Auth ---------- */
.auth { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--auth-grad); }
.auth__card { width: min(400px, 100%); text-align: center; }
.auth__logo { width: 160px; height: 160px; margin: 0 auto 1.25rem; display: block; }
.auth__card h1 { font-family: var(--font-display); font-size: 1.85rem; letter-spacing: -0.015em; margin-bottom: 0.4rem; }
.auth__card > p { margin: 0 0 1.5rem; color: var(--muted); }
.auth__card .field { text-align: left; }
.auth__card .btn { width: 100%; margin-top: 1.25rem; }
.auth-forgot { display: block; margin-top: 0.6rem; text-align: right; font-size: 0.8125rem; color: var(--muted); text-decoration: none; }
.auth-forgot:hover { color: var(--text); }

@media (max-width: 1000px) {
  .edit { grid-template-columns: 1fr; }
  .edit__side { position: static; order: -1; }
}
@media (max-width: 640px) {
  .top { gap: 0.5rem; padding: 0 12px; }
  .brand { font-size: 1.3rem; }
  .brand small { display: none; }
  .nav { gap: 0.4rem; }
  .nav a { padding: 0.4rem 0.5rem; font-size: 0.875rem; }
  .theme-field__label { display: none; }
  .top__right { gap: 0.35rem; }
  .top__right .btn span { display: none; }
  .savebar__status { display: none; }
  .savebar__inner .btn { flex: 1; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
</head>
<body>
<script>
// Password fields: a Show/Hide toggle on every one, on every view (register, login,
// change password, add user, …) — a standalone script since the views below don't
// all share the same page shell.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', 'Show password');
    btn.addEventListener('click', function () {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      btn.textContent = hidden ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(btn);
  });
});
</script>
<?php if (in_array($view, ['register', 'login', 'forgot', 'reset'], true)): ?>
<main class="auth">
  <form class="auth__card panel" method="post" action="admin.php">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <img class="auth__logo" src="images/nexshow-logo.png" alt="NexShoW">
    <?php if ($view === 'register'): ?>
      <input type="hidden" name="action" value="register">
      <h1 style="text-transform: uppercase">Register</h1>
    <?php elseif ($view === 'forgot'): ?>
      <input type="hidden" name="action" value="forgot_password">
      <h1>Forgot password</h1>
      <p>Enter your username and, if it has an email on file, we'll send a reset link.</p>
    <?php elseif ($view === 'reset'): ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="token" value="<?= e($resetToken) ?>">
      <h1>Reset password</h1>
      <p>Choose a new password for your account.</p>
    <?php else: ?>
      <input type="hidden" name="action" value="login">
      <h1>Log in</h1>
      <p>Manage your company's slider.</p>
    <?php endif; ?>
    <?php if (!$dbOk): ?><div class="flash flash--error" role="status">Could not reach the database.</div><?php endif; ?>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
    <?php if ($view === 'register'): ?>
      <label class="field"><span>Company name</span><input type="text" name="companyName" required autofocus maxlength="120"></label>
    <?php endif; ?>
    <?php if ($view === 'register' || $view === 'login' || $view === 'forgot'): ?>
      <label class="field"><span>Username</span><input type="text" name="username" required<?= $view !== 'register' ? ' autofocus' : '' ?> autocomplete="username" maxlength="80"></label>
    <?php endif; ?>
    <?php if ($view === 'register'): ?>
      <label class="field"><span>Email</span><input type="email" name="email" autocomplete="email" maxlength="190"></label>
    <?php endif; ?>
    <?php if ($view === 'login' || $view === 'register' || $view === 'reset'): ?>
      <label class="field"><span><?= $view === 'reset' ? 'New password' : 'Password' ?></span><input type="password" name="password" required autocomplete="<?= $view === 'login' ? 'current-password' : 'new-password' ?>"<?= $view !== 'login' ? ' minlength="8"' : '' ?>></label>
    <?php endif; ?>
    <?php if ($view === 'register' || $view === 'reset'): ?>
      <label class="field"><span>Type it again</span><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
    <?php endif; ?>
    <?php if ($view === 'login'): ?>
      <a class="auth-forgot" href="admin.php?view=forgot">Forgot password?</a>
    <?php endif; ?>
    <button class="btn btn--primary" type="submit"><?= ['register' => 'Register', 'forgot' => 'Send reset link', 'reset' => 'Reset password'][$view] ?? 'Log in' ?></button>
  </form>
  <p style="text-align:center;margin-top:1rem;color:var(--muted);font-size:0.875rem">
    <?php if ($view === 'register'): ?>
      Already have an account? <a href="admin.php?view=login">Log in</a>
    <?php elseif ($view === 'forgot' || $view === 'reset'): ?>
      <a href="admin.php?view=login">Back to log in</a>
    <?php else: ?>
      New company? <a href="admin.php?view=register">Register</a>
    <?php endif; ?>
  </p>
</main>
<?php else: ?>

<header class="top">
  <a class="brand" href="admin.php"><?= e($settings['pageTitle']) ?><small>ADMIN</small></a>
  <nav class="nav">
    <a href="admin.php"<?= $view !== 'settings' ? ' aria-current="page"' : '' ?>>Frames</a>
    <a href="admin.php?view=settings"<?= $view === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
  </nav>
  <div class="top__right">
    <div class="theme-field">
      <span class="theme-field__label" id="theme-label" data-theme-label>Dark mode</span>
      <button type="button" class="theme-switch" data-theme-toggle role="switch" aria-checked="false" aria-labelledby="theme-label"><span class="theme-switch__thumb"></span></button>
    </div>
    <form method="post" action="admin.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn--sm btn--ghost" type="submit">Log out</button>
    </form>
  </div>
</header>

<main class="wrap">
<?php if ($flash): ?><div class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
<?php if (!$dbOk): ?><div class="flash flash--error" role="status">Could not reach the database. Frames shown below may be out of date, and nothing can be added, edited or deleted right now.</div><?php endif; ?>

<?php if ($view === 'frames'): ?>
  <div class="page-head">
    <div>
      <h1>Frames</h1>
      <p><?= count($frames) ?> frame<?= count($frames) === 1 ? '' : 's' ?>, shown in this order. Each stays on screen for <?= e($defaultSeconds) ?>s unless you set its own time.</p>
    </div>
    <a class="btn btn--primary" href="admin.php?view=edit">+ Add frame</a>
  </div>

  <div class="grid">
  <?php foreach ($frames as $i => $fr):
      $thumb = xs_thumb($fr['image'] ?? '');
      $thumbIsVideo = xs_is_video_path($thumb);
      $sec = isset($fr['duration']) ? $fr['duration'] / 1000 : $defaultSeconds;
      $editUrl = 'admin.php?view=edit&id=' . rawurlencode($fr['id']);
  ?>
    <article class="card">
      <a class="card__thumb" href="<?= e($editUrl) ?>" aria-label="Edit frame <?= $i + 1 ?>">
        <?php if ($thumb !== '' && $thumbIsVideo): ?><video src="<?= e($thumb) ?>" muted loop playsinline preload="metadata" style="object-position: <?= e($fr['focalPoint'] ?? '50% 50%') ?>"></video>
        <?php elseif ($thumb !== ''): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy" style="object-position: <?= e($fr['focalPoint'] ?? '50% 50%') ?>"><?php endif; ?>
        <span class="card__num"><?= $i + 1 ?></span>
        <span class="card__caption">
          <?php if (!empty($fr['eyebrow'])): ?><p class="card__eyebrow"><?= e($fr['eyebrow']) ?></p><?php endif; ?>
          <span class="card__title"><?= e(($fr['title'] ?? '') !== '' ? $fr['title'] : 'Untitled frame') ?></span>
        </span>
      </a>
      <div class="card__body">
        <span class="card__meta"><?= e(ucfirst($fr['transition'] ?? $settings['transition'])) ?> · <?= e($sec) ?>s</span>
        <?php foreach ([-1 => ['↑', 'Move earlier', $i === 0], 1 => ['↓', 'Move later', $i === count($frames) - 1]] as $dir => [$icon, $label, $disabled]): ?>
          <form method="post" action="admin.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="id" value="<?= e($fr['id']) ?>">
            <input type="hidden" name="dir" value="<?= $dir ?>">
            <button class="btn btn--sm btn--icon btn--ghost" type="submit" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $disabled ? ' disabled' : '' ?>><?= $icon ?></button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="admin.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="duplicate">
          <input type="hidden" name="id" value="<?= e($fr['id']) ?>">
          <button class="btn btn--sm btn--icon btn--ghost" type="submit" aria-label="Duplicate" title="Duplicate">⧉</button>
        </form>
        <form method="post" action="admin.php" data-confirm="Delete this frame? This cannot be undone.">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="delete_frame">
          <input type="hidden" name="id" value="<?= e($fr['id']) ?>">
          <button class="btn btn--sm btn--icon btn--ghost btn--danger" type="submit" aria-label="Delete" title="Delete">✕</button>
        </form>
        <a class="btn btn--sm" href="<?= e($editUrl) ?>">Edit</a>
      </div>
    </article>
  <?php endforeach; ?>
    <a class="card card--add" href="admin.php?view=edit"><span><b>+</b>Add a frame</span></a>
  </div>

<?php elseif ($view === 'edit'):
    $isNew = $editIdx < 0;
    $back = $isNew ? 'view=edit' : 'view=edit&id=' . rawurlencode($editId);
?>
  <div class="page-head">
    <div>
      <a class="back" href="admin.php">← All frames</a>
      <h1><?= $isNew ? 'New frame' : 'Frame ' . ($editIdx + 1) ?></h1>
    </div>
  </div>

  <div class="edit">
    <form id="frame-form" method="post" action="admin.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_frame">
      <input type="hidden" name="id" value="<?= e($isNew ? '' : $editId) ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">

      <section class="panel">
        <h2>Images</h2>
        <div class="images">
          <?php xs_image_field('image', 'TV / desktop', 'Photo, or video up to 40 MB', (string) ($raw['image'] ?? ''), 'focalPoint', (string) $f['focalPoint'], true); ?>
          <?php xs_image_field('mobileImage', 'Phone', 'Optional portrait photo', (string) ($raw['mobileImage'] ?? ''), 'mobileFocalPoint', (string) ($raw['mobileFocalPoint'] ?? '')); ?>
        </div>
        <p class="hint">If the TV / desktop slot is a video, it plays on a loop there (with sound where the browser allows it). The Phone photo (or, if left empty, the same video) is what phones show.</p>
        <div class="row">
          <label class="field"><span>Image description <em>read aloud by screen readers</em></span><input type="text" name="alt" value="<?= e($raw['alt'] ?? '') ?>" maxlength="200"></label>
        </div>
        <div class="row">
          <label class="check"><input type="checkbox" name="kenBurns" value="1"<?= $f['kenBurns'] ? ' checked' : '' ?>> Slow zoom on the image while the frame is showing</label>
        </div>
      </section>

      <section class="panel">
        <h2>Table <small>optional &mdash; a data table over the frame, like a rate board</small></h2>
        <div class="row">
          <label class="check"><input type="checkbox" name="tableEnabled" value="1"<?= !empty($table['enabled']) ? ' checked' : '' ?>> Show a table on this frame</label>
          <label class="check"><input type="checkbox" name="tableHeaderRow" value="1"<?= !empty($table['headerRow']) ? ' checked' : '' ?>> First row is a header</label>
        </div>
        <div class="row">
          <div class="field"><span>Position, across</span><?php xs_seg('tableHAlign', ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], $table['hAlign'] ?? 'center'); ?></div>
          <div class="field"><span>Position, up and down</span><?php xs_seg('tableVAlign', ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom'], $table['vAlign'] ?? 'center'); ?></div>
        </div>
        <div class="row">
          <div class="field"><span>Table width</span>
            <div class="range-row"><input type="range" name="tableWidth" min="0" max="100" step="5" value="<?= e($table['width'] ?? 0) ?>" data-table-width><output data-table-width-out><?= e(($table['width'] ?? 0) > 0 ? $table['width'] . '%' : 'Auto') ?></output></div>
          </div>
        </div>
        <div class="row">
          <div class="field"><span>Data text size</span>
            <div class="range-row"><input type="range" name="tableFontSize" min="10" max="200" step="1" value="<?= e($table['fontSize'] ?? 16) ?>" data-range data-unit="px"><output><?= e($table['fontSize'] ?? 16) ?>px</output></div>
          </div>
          <div class="field"><span>Header text size</span>
            <div class="range-row"><input type="range" name="tableHeaderFontSize" min="10" max="200" step="1" value="<?= e($table['headerFontSize'] ?? $table['fontSize'] ?? 16) ?>" data-range data-unit="px"><output><?= e($table['headerFontSize'] ?? $table['fontSize'] ?? 16) ?>px</output></div>
          </div>
          <div class="field"><span>Background opacity</span>
            <div class="range-row"><input type="range" name="tableBgOpacity" min="0" max="1" step="0.05" value="<?= e($table['bgOpacity'] ?? 0.55) ?>" data-range><output><?= e(round(($table['bgOpacity'] ?? 0.55) * 100)) ?>%</output></div>
          </div>
        </div>
        <div class="row">
          <label class="field"><span>Text colour</span><input type="color" name="tableTextColor" value="<?= e($table['textColor'] ?? '#ffffff') ?>"></label>
          <label class="field"><span>Header colour</span><input type="color" name="tableHeaderColor" value="<?= e($table['headerColor'] ?? '#ff2d8f') ?>"></label>
          <label class="field"><span>Border colour</span><input type="color" name="tableBorderColor" value="<?= e($table['borderColor'] ?? '#ffffff') ?>"></label>
          <label class="field"><span>Border thickness <em>px</em></span><input type="number" name="tableBorderWidth" min="0" max="8" value="<?= e($table['borderWidth'] ?? 1) ?>"></label>
        </div>
        <div class="table-editor" data-table-editor>
          <p class="hint">Click the small swatch beside a row or above a column to tint its background.</p>
          <div class="table-editor__grid" data-table-grid></div>
          <div class="table-editor__toolbar">
            <button type="button" class="btn btn--sm" data-table-add-row>+ Row</button>
            <button type="button" class="btn btn--sm" data-table-add-col>+ Column</button>
          </div>
        </div>
        <?php if ($tags): ?>
          <p class="hint">Click to insert into the focused cell:
            <?php foreach ($tags as $tname => $tval): ?>
              <button type="button" class="tag-chip" data-insert-tag="<?= e($tname) ?>" title="<?= e($tval) ?>">{{<?= e($tname) ?>}}</button>
            <?php endforeach; ?>
          </p>
        <?php else: ?>
          <p class="hint">No tags yet. Add one in Settings, then use it here as {{name}} to auto-fill a value like today's rate everywhere it appears.</p>
        <?php endif; ?>
        <input type="hidden" name="tableRows" data-table-rows-json value="<?= e(json_encode($tableGrid)) ?>">
      </section>

      <section class="panel">
        <h2>Text</h2>
        <div class="row">
          <label class="field"><span>Small heading <em>shown in gold above the title</em></span><input type="text" name="eyebrow" value="<?= e($raw['eyebrow'] ?? '') ?>" maxlength="80"></label>
        </div>
        <div class="row">
          <label class="field"><span>Title</span><input type="text" name="title" value="<?= e($raw['title'] ?? '') ?>" maxlength="200"></label>
        </div>
        <div class="row">
          <label class="field"><span>Description</span><textarea name="description" maxlength="600"><?= e($raw['description'] ?? '') ?></textarea></label>
        </div>
        <div class="row">
          <label class="field"><span>Button text <em>leave empty for no button</em></span><input type="text" name="buttonText" value="<?= e($raw['buttonText'] ?? '') ?>" maxlength="60"></label>
          <label class="field"><span>Button link</span><input type="text" name="buttonUrl" value="<?= e($raw['buttonUrl'] ?? '') ?>" placeholder="/collection or https://…" spellcheck="false"></label>
        </div>
        <div class="row">
          <label class="check"><input type="checkbox" name="openInNewTab" value="1"<?= $f['openInNewTab'] ? ' checked' : '' ?>> Open the button link in a new tab</label>
        </div>
      </section>

      <section class="panel">
        <h2>Layout</h2>
        <div class="row">
          <div class="field"><span>Text position, across</span><?php xs_seg('textAlign', ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], $f['textAlign']); ?></div>
          <div class="field"><span>Text position, up and down</span><?php xs_seg('verticalAlign', ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom'], $f['verticalAlign']); ?></div>
        </div>
        <div class="row">
          <label class="field"><span>Nudge sideways <em>desktop only</em></span><input type="text" name="offsetX" value="<?= e($f['offsetX']) ?>" placeholder="0px" spellcheck="false"></label>
          <label class="field"><span>Nudge up / down <em>desktop only</em></span><input type="text" name="offsetY" value="<?= e($f['offsetY']) ?>" placeholder="0px" spellcheck="false"></label>
        </div>
      </section>

      <section class="panel">
        <h2>Shading <small>darkens the photo so the text is easy to read</small></h2>
        <div class="row">
          <label class="check"><input type="checkbox" name="overlay" value="1"<?= $f['overlay'] ? ' checked' : '' ?>> Shade the photo</label>
        </div>
        <div class="row">
          <label class="field"><span>Shade from</span><?php xs_select('overlayDirection', ['auto' => 'Automatic (follows the text)', 'left' => 'Left side', 'right' => 'Right side', 'bottom' => 'Bottom', 'full' => 'Whole photo'], $f['overlayDirection']); ?></label>
          <div class="field"><span>Strength</span>
            <div class="range-row"><input type="range" name="overlayStrength" min="0" max="1" step="0.05" value="<?= e($f['overlayStrength']) ?>" data-range><output><?= e(round($f['overlayStrength'] * 100)) ?>%</output></div>
          </div>
        </div>
      </section>

      <section class="panel">
        <h2>Type</h2>
        <div class="row">
          <label class="field"><span>Text colour</span><input type="color" name="textColor" value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) $f['textColor']) ? $f['textColor'] : '#ffffff') ?>"></label>
          <label class="field"><span>Title font</span><?php xs_select('fontFamily', ['' => 'Bodoni Moda (default)', "'Instrument Sans', sans-serif" => 'Instrument Sans', "Georgia, serif" => 'Georgia'], $raw['fontFamily'] ?? ''); ?></label>
        </div>
        <div class="row">
          <label class="field"><span>Title size</span><?php xs_select('titleSize', ['clamp(2rem, 4vw, 3.5rem)' => 'Small', 'clamp(2.25rem, 5vw, 4.5rem)' => 'Medium', '' => 'Large (default)', 'clamp(3rem, 8vw, 7.5rem)' => 'Extra large'], $raw['titleSize'] ?? ''); ?></label>
          <label class="field"><span>Title weight</span><?php xs_select('titleWeight', ['' => 'Regular (default)', '500' => 'Medium', '600' => 'Semibold'], isset($raw['titleWeight']) ? (string) $raw['titleWeight'] : ''); ?></label>
        </div>
      </section>

      <section class="panel">
        <h2>Motion</h2>
        <div class="row">
          <label class="field"><span>How this frame appears</span><?php xs_select('transition', ['' => 'Site default (' . ucfirst($settings['transition']) . ')', 'fade' => 'Fade', 'zoom' => 'Zoom', 'drift' => 'Drift'], $raw['transition'] ?? ''); ?></label>
          <label class="field"><span>Seconds on screen</span><input type="number" name="duration" min="1" max="60" step="0.5" value="<?= isset($raw['duration']) ? e($raw['duration'] / 1000) : '' ?>" placeholder="Site default (<?= e($defaultSeconds) ?>)"></label>
        </div>
      </section>

    </form>

    <aside class="edit__side">
      <section class="panel preview">
        <div class="preview__bar">
          <span class="label">Preview</span>
          <div class="seg" role="radiogroup" aria-label="Preview size">
            <label><input type="radio" name="pv" value="tv" checked data-pv><span>TV</span></label>
            <label><input type="radio" name="pv" value="phone" data-pv><span>Phone</span></label>
          </div>
        </div>
        <?php if ($isNew): ?>
          <div class="preview__empty">Save the frame to see a preview.</div>
        <?php else: ?>
          <div class="preview__frame" data-preview>
            <iframe src="NexShow.php?preview=1&amp;frame=<?= $editIdx ?>&amp;t=<?= time() ?>" title="Frame preview" loading="lazy" tabindex="-1"></iframe>
          </div>
          <p class="preview__note">Shows the last saved version.</p>
        <?php endif; ?>
      </section>

      <?php if (!$isNew): ?>
      <section class="panel danger-zone">
        <p>Remove this frame from the slider.</p>
        <form method="post" action="admin.php" data-confirm="Delete this frame? This cannot be undone.">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="delete_frame">
          <input type="hidden" name="id" value="<?= e($editId) ?>">
          <button class="btn btn--sm btn--danger" type="submit">Delete frame</button>
        </form>
      </section>
      <?php endif; ?>
    </aside>
  </div>

  <div class="savebar">
    <div class="savebar__inner">
      <span class="savebar__status" data-status><?= $isNew ? 'New frame, not saved yet' : 'All changes saved' ?></span>
      <a class="btn" href="admin.php">Back</a>
      <button class="btn btn--primary" type="submit" form="frame-form"><?= $isNew ? 'Add frame' : 'Save frame' ?></button>
    </div>
  </div>

  <dialog id="library" aria-labelledby="lib-title">
    <div class="lib__head">
      <h2 id="lib-title" class="label" style="color:var(--text);font-size:1rem" data-lib-title>Image library</h2>
      <button class="btn btn--sm btn--ghost" type="button" data-close>Close</button>
    </div>
    <?php if ($library): ?>
      <div class="lib__grid">
        <?php foreach ($library as $item): $isVideo = xs_is_video_path($item['value']); ?>
          <button class="lib__item" type="button" data-pick="<?= e($item['value']) ?>" data-kind="<?= $isVideo ? 'video' : 'image' ?>">
            <?php if ($isVideo): ?>
              <video src="<?= e($item['url']) ?>" muted preload="metadata"></video>
            <?php else: ?>
              <img src="<?= e($item['url']) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <span><?= e($item['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="lib__empty">No images yet. Use Upload to add one.</p>
    <?php endif; ?>
  </dialog>

<?php elseif ($view === 'settings'):
    $unused = count(array_filter($library, function ($it) { return !$it['used'] && xs_is_frame_file($it['value']); }));
    $companyUsers = $canManageUsers ? xs_db_list_company_users($companyId) : [];
    $company = xs_db_get_company($companyId);
    $me = xs_db_find_user_by_id((int) $_SESSION['xs_user_id']);
    // ?activate=<username> (set by the add_user redirect) auto-opens the payment modal for that
    // user — but only if they're genuinely still pending, so a stale/reused link can't do anything.
    $activateUser = null;
    $activateUsername = (string) ($_GET['activate'] ?? '');
    if ($canManageUsers && $activateUsername !== '') {
        foreach ($companyUsers as $u) {
            if ($u['username'] === $activateUsername && $u['payment_status'] === 'pending') { $activateUser = $u; break; }
        }
    }
?>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <p>These apply to every frame unless a frame sets its own.</p>
    </div>
  </div>

  <?php if ($canManageUsers): ?>
  <section class="panel">
    <h2>Users <small><?= e($company['name'] ?? 'your company') ?> &mdash; Add User For NexShow Client Login.</small></h2>
    <?php if ($companyUsers): ?>
      <?php foreach ($companyUsers as $u):
          $isSelf = (int) $u['id'] === (int) $_SESSION['xs_user_id'];
          // A user only ever skips the payment flow and gets the plain Reactivate toggle once
          // its fee is actually marked 'paid' — 'pending' (awaiting confirmation) or 'none'
          // (never paid, or expired and reset back to needing a fresh one) both route through
          // the payment modal instead, same as a brand new user does.
          $needsPayment = $u['status'] !== 'active' && $u['payment_status'] !== 'paid';
          $statusLabel = $u['status'] === 'active' ? 'Active' : ($needsPayment ? ($u['payment_status'] === 'pending' ? 'Pending payment' : 'Needs payment') : 'Inactive');
      ?>
        <div class="user-block">
          <div class="tag-row">
            <div class="field" style="flex:1">
              <span><?= e($u['username']) ?><?= $isSelf ? ' (you)' : '' ?></span>
              <span class="hint"><?= e(ucfirst($u['role'])) ?> &middot; <?= e($statusLabel) ?> &middot; <?php
                if (empty($u['expires_at'])) { echo 'Never expires'; }
                elseif (strtotime($u['expires_at']) <= time()) { echo 'Expired'; }
                else { echo 'Expires ' . e(date('M j, Y', strtotime($u['expires_at']))); }
              ?></span>
            </div>
            <?php if ($needsPayment): ?>
              <button class="btn btn--sm btn--success" type="button" data-pay-user="<?= e($u['username']) ?>">Pay to activate</button>
            <?php elseif (!$isSelf): ?>
            <form method="post" action="admin.php">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="back" value="view=settings">
              <input type="hidden" name="userId" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
              <button class="btn btn--sm<?= $u['status'] === 'active' ? ' btn--danger' : '' ?>" type="submit"><?= $u['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php if (!$isSelf): ?>
          <form method="post" action="admin.php" class="row user-block__reset">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reset_user_password">
            <input type="hidden" name="back" value="view=settings">
            <input type="hidden" name="userId" value="<?= (int) $u['id'] ?>">
            <label class="field"><span>New password for <?= e($u['username']) ?></span><input type="password" name="password" minlength="8" placeholder="Set &amp; reveal with Show"></label>
            <div style="align-self: end;"><button class="btn btn--sm" type="submit">Reset password</button></div>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <form method="post" action="admin.php" class="row">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="add_user">
      <input type="hidden" name="back" value="view=settings">
      <label class="field"><span>Username</span><input type="text" name="username" maxlength="80" required></label>
      <label class="field"><span>Password</span><input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
      <label class="field"><span>Role</span><?php xs_select('role', ['user' => 'User', 'admin' => 'Admin'], 'user'); ?></label>
      <div style="align-self: end;"><button class="btn btn--sm btn--primary" type="submit">Add user</button></div>
    </form>
  </section>

  <dialog id="payModal" aria-labelledby="pay-title">
    <div data-pay-screen="info">
      <div class="pay__icon">⚠️</div>
      <h2 id="pay-title">Activate <span data-pay-username></span></h2>
      <p>This user needs a one-time activation payment of <strong>&#8377;1,999</strong> before it can sign in.</p>
      <div class="pay__actions">
        <button class="btn btn--primary" type="button" data-pay-next="pay">Make Payment</button>
        <a class="btn btn--ghost" href="https://wa.me/917385250853" target="_blank" rel="noopener">WhatsApp Support</a>
        <button class="btn btn--ghost" type="button" data-close>Close</button>
      </div>
    </div>
    <div data-pay-screen="pay" hidden>
      <h2>Pay &#8377;1,999</h2>
      <p>Scan the QR code or use the UPI ID below, then let us know once you've paid.</p>
      <div class="pay__qr"><div id="payQr"></div></div>
      <p class="pay__upi">UPI ID: <strong>7385250853@yapl</strong></p>
      <div class="pay__actions">
        <a class="btn btn--primary" id="payUpiBtn" href="#">Pay via UPI App</a>
        <button class="btn btn--success" type="button" data-pay-next="confirm">I've Paid, Notify Support</button>
      </div>
    </div>
    <div data-pay-screen="confirm" hidden>
      <h2>Confirm payment</h2>
      <p>Enter the transaction/reference ID from your payment app so we can verify it.</p>
      <label class="field"><span>Transaction ID</span><input type="text" id="payTxnId" placeholder="e.g. 123456789012" autocomplete="off"></label>
      <p class="pay__error" id="payTxnError" hidden>Enter your transaction ID.</p>
      <div class="pay__actions">
        <button class="btn btn--success" type="button" id="paySubmit">Submit &amp; Notify Support</button>
      </div>
    </div>
  </dialog>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script>
  (function () {
    var dlg = document.getElementById('payModal');
    if (!dlg) return;
    var UPI_ID = '7385250853@yapl';
    var PAYEE_NAME = 'AB Studio Digital';
    var AMOUNT = 1999;
    var WHATSAPP = '917385250853';
    var companyName = <?= json_encode($company['name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var currentUsername = '';

    function showScreen(name) {
      dlg.querySelectorAll('[data-pay-screen]').forEach(function (el) {
        el.hidden = el.dataset.payScreen !== name;
      });
    }

    function openFor(username) {
      currentUsername = username;
      dlg.querySelectorAll('[data-pay-username]').forEach(function (el) { el.textContent = username; });
      showScreen('info');
      if (typeof dlg.showModal === 'function') dlg.showModal();
    }

    function buildUpiLink() {
      var note = encodeURIComponent('Activate user ' + currentUsername);
      return 'upi://pay?pa=' + UPI_ID + '&pn=' + encodeURIComponent(PAYEE_NAME) + '&am=' + AMOUNT + '&cu=INR&tn=' + note;
    }

    dlg.querySelectorAll('[data-pay-next]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = btn.dataset.payNext;
        showScreen(next);
        if (next === 'pay') {
          var link = buildUpiLink();
          document.getElementById('payUpiBtn').href = link;
          var qrEl = document.getElementById('payQr');
          qrEl.innerHTML = '';
          if (window.QRCode) new QRCode(qrEl, { text: link, width: 170, height: 170, colorDark: '#000000', colorLight: '#ffffff' });
        }
      });
    });

    var txnInput = document.getElementById('payTxnId');
    var txnError = document.getElementById('payTxnError');
    document.getElementById('paySubmit').addEventListener('click', function () {
      var value = txnInput.value.trim();
      if (!value) { txnError.hidden = false; return; }
      txnError.hidden = true;
      var message = "Hi, I've paid to activate a user.\nCompany: " + companyName +
        '\nUsername: ' + currentUsername + '\nAmount: ₹' + AMOUNT + '\nTransaction ID: ' + value;
      window.open('https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(message), '_blank');
      dlg.close();
      txnInput.value = '';
    });
    txnInput.addEventListener('input', function () { txnError.hidden = true; });

    dlg.querySelectorAll('[data-close]').forEach(function (btn) {
      btn.addEventListener('click', function () { dlg.close(); });
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });

    document.querySelectorAll('[data-pay-user]').forEach(function (btn) {
      btn.addEventListener('click', function () { openFor(btn.dataset.payUser); });
    });

    <?php if ($activateUser): ?>
    openFor(<?= json_encode($activateUser['username'], JSON_HEX_TAG | JSON_HEX_AMP) ?>);
    <?php endif; ?>
  })();
  </script>
  <?php endif; ?>

  <form method="post" action="admin.php" class="panel">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="back" value="view=settings">
    <h2>Slider</h2>
    <div class="row">
      <label class="field"><span>Page title <em>shown in the browser tab</em></span><input type="text" name="pageTitle" value="<?= e($settings['pageTitle']) ?>" maxlength="80"></label>
      <label class="field"><span>Slider name <em>for screen readers</em></span><input type="text" name="ariaLabel" value="<?= e($settings['ariaLabel']) ?>" maxlength="80"></label>
    </div>
    <div class="row">
      <label class="check"><input type="checkbox" name="autoplay" value="1"<?= $settings['autoplay'] ? ' checked' : '' ?>> Move to the next frame automatically</label>
      <label class="check"><input type="checkbox" name="pauseOnHover" value="1"<?= $settings['pauseOnHover'] ? ' checked' : '' ?>> Pause while the mouse is over the slider</label>
    </div>
    <div class="row">
      <label class="field"><span>Seconds per frame</span><input type="number" name="duration" min="1" max="60" step="0.5" value="<?= e($defaultSeconds) ?>"></label>
      <label class="field"><span>Default transition</span><?php xs_select('transition', ['fade' => 'Fade', 'zoom' => 'Zoom', 'drift' => 'Drift'], $settings['transition']); ?></label>
      <label class="field"><span>Transition length <em>ms</em></span><input type="number" name="transitionMs" min="100" max="5000" step="50" value="<?= e($settings['transitionMs']) ?>"></label>
    </div>
    <div class="row">
      <label class="field"><span>Swipe distance <em>px</em></span><input type="number" name="swipeThreshold" min="10" max="400" value="<?= e($settings['swipeThreshold']) ?>"></label>
      <label class="field"><span>Phone layout below <em>px wide</em></span><input type="number" name="mobileBreakpoint" min="320" max="1600" value="<?= e($settings['mobileBreakpoint']) ?>"></label>
      <div class="field"></div>
    </div>
    <div class="row"><div><button class="btn btn--primary" type="submit">Save settings</button></div></div>
  </form>

  <form method="post" action="admin.php" class="panel">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_email">
    <input type="hidden" name="back" value="view=settings">
    <h2>Email <small>used to send you a "forgot password" reset link</small></h2>
    <div class="row">
      <label class="field"><span>Your email</span><input type="email" name="email" value="<?= e($me['email'] ?? '') ?>" maxlength="190" placeholder="you@example.com"></label>
    </div>
    <div class="row"><div><button class="btn" type="submit">Save email</button></div></div>
  </form>

  <form method="post" action="admin.php" class="panel">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="change_password">
    <input type="hidden" name="back" value="view=settings">
    <h2>Password</h2>
    <div class="row">
      <label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="field"><span>New password</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label class="field"><span>New password again</span><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
    </div>
    <div class="row"><div><button class="btn" type="submit">Change password</button></div></div>
  </form>

  <section class="panel">
    <h2>Tags <small>named values usable as {{name}} in a frame's table cells &mdash; update one here, it updates everywhere</small></h2>
    <?php if ($tags): ?>
      <div class="row">
        <?php foreach ($tags as $tname => $tval): ?>
          <div class="tag-row">
            <form method="post" action="admin.php">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="save_tag">
              <input type="hidden" name="back" value="view=settings">
              <input type="hidden" name="tagName" value="<?= e($tname) ?>">
              <label class="field"><span>{{<?= e($tname) ?>}}</span><input type="text" name="tagValue" value="<?= e($tval) ?>" maxlength="255"></label>
              <button class="btn btn--sm" type="submit">Save</button>
            </form>
            <form method="post" action="admin.php" data-confirm="Delete the tag {{<?= e($tname) ?>}}? Any table cell using it will show the literal text instead.">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="delete_tag">
              <input type="hidden" name="back" value="view=settings">
              <input type="hidden" name="tagName" value="<?= e($tname) ?>">
              <button class="btn btn--sm btn--icon btn--ghost" type="submit" aria-label="Delete tag <?= e($tname) ?>" title="Delete">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="admin.php" class="row">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_tag">
      <input type="hidden" name="back" value="view=settings">
      <label class="field"><span>New tag name</span><input type="text" name="tagName" placeholder="gold24" maxlength="64" pattern="[a-zA-Z0-9_]+"></label>
      <label class="field"><span>Value</span><input type="text" name="tagValue" placeholder="15050" maxlength="255"></label>
      <div style="align-self: end;"><button class="btn btn--sm btn--primary" type="submit">Add tag</button></div>
    </form>
  </section>

  <form method="post" action="admin.php" class="panel danger-zone" data-confirm="Delete <?= $unused ?> uploaded file<?= $unused === 1 ? '' : 's' ?> that no frame uses?">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="cleanup_uploads">
    <input type="hidden" name="back" value="view=settings">
    <p><?= $unused ? "$unused uploaded file" . ($unused === 1 ? ' is' : 's are') . ' not used by any frame.' : 'Every uploaded file is in use.' ?></p>
    <button class="btn btn--sm btn--danger" type="submit"<?= $unused ? '' : ' disabled' ?>>Delete unused files</button>
  </form>
<?php endif; ?>
</main>

<script>
(function () {
  // Confirm before destructive actions.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // Light/dark mode: persisted, applied before paint by the inline script in <head>.
  var themeBtn = document.querySelector('[data-theme-toggle]');
  var themeLabel = document.querySelector('[data-theme-label]');
  if (themeBtn) {
    function renderTheme() {
      var light = document.documentElement.getAttribute('data-theme') === 'light';
      themeBtn.setAttribute('aria-checked', light ? 'true' : 'false');
      if (themeLabel) themeLabel.textContent = light ? 'Light mode' : 'Dark mode';
    }
    themeBtn.addEventListener('click', function () {
      var light = document.documentElement.getAttribute('data-theme') === 'light';
      if (light) document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', 'light');
      try { localStorage.setItem('xshow-admin-theme', light ? 'dark' : 'light'); } catch (e) {}
      renderTheme();
    });
    renderTheme();
  }

  var form = document.getElementById('frame-form');
  if (!form) return;

  var LIB = <?= json_encode(array_column($library, 'url', 'value'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
  var urlFor = function (v) { v = v.trim(); return LIB[v] || v; };

  // Unsaved-changes indicator.
  var dirty = false, status = document.querySelector('[data-status]');
  function markDirty() {
    if (dirty) return;
    dirty = true;
    status.textContent = 'Unsaved changes';
    status.classList.add('is-dirty');
  }
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // Slider readouts. data-unit="px" shows the raw value; anything else (default) shows it as a percentage.
  form.querySelectorAll('[data-range]').forEach(function (r) {
    var out = r.nextElementSibling;
    var px = r.dataset.unit === 'px';
    r.addEventListener('input', function () { out.textContent = Math.round(r.value * (px ? 1 : 100)) + (px ? 'px' : '%'); });
  });

  // Table width: 0 reads as "Auto" (size to fit the content) instead of "0%".
  var widthSlider = form.querySelector('[data-table-width]');
  if (widthSlider) {
    var widthOut = form.querySelector('[data-table-width-out]');
    widthSlider.addEventListener('input', function () {
      widthOut.textContent = widthSlider.value > 0 ? widthSlider.value + '%' : 'Auto';
    });
  }

  // Image/video fields: upload preview, path typing, remove.
  var VIDEO_EXT = /\.(mp4|webm|mov|m4v)$/i;
  document.querySelectorAll('[data-imgfield]').forEach(function (box) {
    var img = box.querySelector('[data-img]'), vid = box.querySelector('[data-vid]'), stage = box.querySelector('[data-stage]');
    var path = box.querySelector('[data-path]'), file = box.querySelector('[data-file]');

    function show(url, isVideo) {
      if (!url) {
        img.removeAttribute('src'); vid.removeAttribute('src');
        img.hidden = true; vid.hidden = true;
        stage.classList.add('is-empty');
        return;
      }
      stage.classList.remove('is-empty');
      if (isVideo) { vid.src = url; vid.hidden = false; img.hidden = true; img.removeAttribute('src'); }
      else { img.src = url; img.hidden = false; vid.hidden = true; vid.removeAttribute('src'); }
    }
    box.setValue = function (v) {
      path.value = v; path.placeholder = ''; file.value = '';
      show(urlFor(v), VIDEO_EXT.test(v)); markDirty();
    };

    path.addEventListener('input', function () { file.value = ''; path.placeholder = ''; show(urlFor(path.value), VIDEO_EXT.test(path.value)); });
    file.addEventListener('change', function () {
      var f = file.files[0];
      if (!f) return;
      show(URL.createObjectURL(f), f.type.indexOf('video/') === 0);
      path.value = '';
      path.placeholder = 'Uploads on save: ' + f.name;
    });
    box.querySelector('[data-clear]').addEventListener('click', function () { box.setValue(''); });
    img.addEventListener('error', function () { if (!img.hidden && img.getAttribute('src')) stage.classList.add('is-empty'); });
    vid.addEventListener('error', function () { if (!vid.hidden && vid.getAttribute('src')) stage.classList.add('is-empty'); });
  });

  // Image/video library — filtered to the kind the opening field accepts.
  var dlg = document.getElementById('library'), target = null;
  var libTitle = dlg.querySelector('[data-lib-title]');
  var libItems = dlg.querySelectorAll('[data-pick]');
  document.querySelectorAll('[data-open-library]').forEach(function (b) {
    b.addEventListener('click', function () {
      target = b.closest('[data-imgfield]');
      var kind = b.dataset.libraryKind || 'image';
      libItems.forEach(function (it) { it.hidden = kind !== 'media' && it.dataset.kind !== kind; });
      libTitle.textContent = kind === 'media' ? 'Image / video library' : 'Image library';
      dlg.showModal();
    });
  });
  dlg.addEventListener('click', function (e) {
    var pick = e.target.closest('[data-pick]');
    if (pick && target) { target.setValue(pick.dataset.pick); dlg.close(); }
    else if (e.target === dlg || e.target.closest('[data-close]')) dlg.close();
  });

  // Table editor: rows/columns of plain cells + optional per-row/per-column tint, serialized on every change.
  var tableEditor = document.querySelector('[data-table-editor]');
  if (tableEditor) {
    var grid = tableEditor.querySelector('[data-table-grid]');
    var rowsInput = document.querySelector('[data-table-rows-json]');
    var headerCheckbox = form.querySelector('[name="tableHeaderRow"]');
    var lastFocusedCell = null;
    var rows = [], rowColors = [], colColors = [];
    try {
      var saved = JSON.parse(rowsInput.value) || {};
      rows = Array.isArray(saved.rows) ? saved.rows : [];
      rowColors = Array.isArray(saved.rowColors) ? saved.rowColors : [];
      colColors = Array.isArray(saved.colColors) ? saved.colColors : [];
    } catch (e) {}
    if (!rows.length) rows = [['', '']];

    function sync() {
      rowsInput.value = JSON.stringify({ rows: rows, rowColors: rowColors, colColors: colColors });
      markDirty();
    }

    // A color swatch plus a small clear button, bound to get/set(value) for one row or column.
    function colorControl(get, set, label) {
      var wrap = document.createElement('span');
      wrap.className = 'table-editor__swatch-wrap';
      var input = document.createElement('input');
      input.type = 'color';
      input.className = 'table-editor__swatch';
      input.title = label;
      input.value = get() || '#000000';
      input.addEventListener('input', function () { set(input.value); sync(); });
      wrap.appendChild(input);
      if (get()) {
        var clear = document.createElement('button');
        clear.type = 'button'; clear.className = 'table-editor__swatch-clear';
        clear.title = 'Remove this tint'; clear.textContent = '⊘';
        clear.addEventListener('click', function () { set(''); sync(); renderGrid(); });
        wrap.appendChild(clear);
      }
      return wrap;
    }

    function renderGrid() {
      var cols = rows.reduce(function (n, r) { return Math.max(n, r.length); }, 1);
      var table = document.createElement('table');

      // Column-remove row, with a tint swatch per column.
      var head = document.createElement('tr');
      head.appendChild(document.createElement('td')).className = 'table-editor__corner';
      for (var c = 0; c < cols; c++) {
        (function (c) {
          var td = document.createElement('td');
          td.className = 'table-editor__head-cell';
          if (cols > 1) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'table-editor__col-remove'; btn.title = 'Remove this column';
            btn.setAttribute('aria-label', 'Remove column ' + (c + 1));
            btn.textContent = '×';
            btn.addEventListener('click', function () {
              rows.forEach(function (r) { r.splice(c, 1); });
              colColors.splice(c, 1);
              sync(); renderGrid();
            });
            td.appendChild(btn);
          }
          td.appendChild(colorControl(
            function () { return colColors[c] || ''; },
            function (v) { colColors[c] = v; },
            'Tint column ' + (c + 1)
          ));
          head.appendChild(td);
        })(c);
      }
      table.appendChild(head);

      rows.forEach(function (row, r) {
        var tr = document.createElement('tr');
        if (r === 0 && headerCheckbox && headerCheckbox.checked) tr.className = 'is-header-row';
        var rowBtnCell = document.createElement('td');
        rowBtnCell.className = 'table-editor__head-cell';
        if (rows.length > 1) {
          var rbtn = document.createElement('button');
          rbtn.type = 'button'; rbtn.className = 'table-editor__row-remove'; rbtn.title = 'Remove this row';
          rbtn.setAttribute('aria-label', 'Remove row ' + (r + 1));
          rbtn.textContent = '×';
          rbtn.addEventListener('click', function () { rows.splice(r, 1); rowColors.splice(r, 1); sync(); renderGrid(); });
          rowBtnCell.appendChild(rbtn);
        }
        rowBtnCell.appendChild(colorControl(
          function () { return rowColors[r] || ''; },
          function (v) { rowColors[r] = v; },
          'Tint row ' + (r + 1)
        ));
        tr.appendChild(rowBtnCell);
        for (var c = 0; c < cols; c++) {
          (function (r, c) {
            var td = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'text';
            input.value = row[c] || '';
            input.placeholder = r === 0 ? 'Header ' + (c + 1) : '';
            input.addEventListener('focus', function () { lastFocusedCell = input; });
            input.addEventListener('input', function () { row[c] = input.value; sync(); });
            td.appendChild(input);
            tr.appendChild(td);
          })(r, c);
        }
        table.appendChild(tr);
      });

      grid.innerHTML = '';
      grid.appendChild(table);
    }

    tableEditor.querySelector('[data-table-add-row]').addEventListener('click', function () {
      var cols = rows.length ? rows[0].length : 2;
      var blank = []; for (var i = 0; i < cols; i++) blank.push('');
      rows.push(blank);
      sync(); renderGrid();
    });
    tableEditor.querySelector('[data-table-add-col]').addEventListener('click', function () {
      rows.forEach(function (r) { r.push(''); });
      sync(); renderGrid();
    });

    document.querySelectorAll('[data-insert-tag]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        if (!lastFocusedCell) { alert('Click into a table cell first, then click a tag to insert it there.'); return; }
        var tag = '{{' + chip.dataset.insertTag + '}}';
        var el = lastFocusedCell, start = el.selectionStart || el.value.length, end = el.selectionEnd || el.value.length;
        el.value = el.value.slice(0, start) + tag + el.value.slice(end);
        el.dispatchEvent(new Event('input'));
        el.focus();
        el.selectionStart = el.selectionEnd = start + tag.length;
      });
    });

    if (headerCheckbox) headerCheckbox.addEventListener('change', renderGrid);

    renderGrid();
  }

  // Preview: render the real page at full size, scaled to fit.
  var pv = document.querySelector('[data-preview]');
  if (pv) {
    var frame = pv.querySelector('iframe'), mode = 'tv';
    // The preview page is its own fullscreen, non-scrolling document (overflow: hidden), so a
    // wheel scroll over it would otherwise just do nothing instead of scrolling this admin page.
    // Same-origin iframe, so we can reach in and forward the scroll to the parent ourselves.
    frame.addEventListener('load', function () {
      try {
        var doc = frame.contentDocument;
        doc.addEventListener('wheel', function (e) {
          window.scrollBy(0, e.deltaY);
        }, { passive: true });
        // Touch devices have no wheel event — forward vertical drag the same way. Reads
        // alongside the preview's own touch handling (slide swipe) rather than replacing it,
        // since this only observes touches and never calls preventDefault.
        var touchY = null;
        doc.addEventListener('touchstart', function (e) {
          touchY = e.touches.length === 1 ? e.touches[0].clientY : null;
        }, { passive: true });
        doc.addEventListener('touchmove', function (e) {
          if (touchY === null || e.touches.length !== 1) return;
          var y = e.touches[0].clientY;
          window.scrollBy(0, touchY - y);
          touchY = y;
        }, { passive: true });
        doc.addEventListener('touchend', function () { touchY = null; }, { passive: true });
      } catch (e) {}
    });
    function fit() {
      // TV: wide landscape, like a living-room screen. Phone: tall portrait.
      var w = mode === 'phone' ? 390 : 1920, h = mode === 'phone' ? 844 : 1080;
      var avail = pv.clientWidth;
      var s = Math.min(avail / w, mode === 'phone' ? 520 / h : 1);
      frame.style.width = w + 'px';
      frame.style.height = h + 'px';
      frame.style.transform = 'scale(' + s + ')';
      frame.style.left = Math.max(0, (avail - w * s) / 2) + 'px';
      pv.style.height = Math.round(h * s) + 'px';
    }
    document.querySelectorAll('[data-pv]').forEach(function (r) {
      r.addEventListener('change', function () { if (r.checked) { mode = r.value; fit(); } });
    });
    window.addEventListener('resize', fit);
    fit();
  }
})();
</script>
<?php endif; ?>
</body>
</html>
