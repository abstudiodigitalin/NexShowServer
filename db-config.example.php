<?php
/**
 * Database connection for the frames table. Separate from any other
 * project's database — this file only ever touches NexShoW_db.
 *
 * Multi-tenant: every company's frames, site_settings and tags are scoped by
 * company_id. That value must always come from a trusted server-side source
 * (the admin session's user row, or the device session's site_settings row)
 * — never from a request parameter — so one company can never read or
 * change another's data.
 *
 * ---
 * This is the template version, committed to git. The real db-config.php (gitignored,
 * since it holds real credentials) starts as a copy of this file with the five
 * constants below replaced:
 *
 *   cp db-config.example.php db-config.php
 */
define('XS_DB_HOST', '127.0.0.1');
define('XS_DB_PORT', 3306);
define('XS_DB_NAME', 'NexShoW_db');
define('XS_DB_USER', 'your_db_user');
define('XS_DB_PASS', 'your_db_password');

/** Shared PDO connection. Returns null (never throws) if MySQL is unreachable. */
function xs_db()
{
    static $pdo = false; // false = not tried yet, null = tried and failed
    if ($pdo !== false) return $pdo;
    try {
        $dsn = 'mysql:host=' . XS_DB_HOST . ';port=' . XS_DB_PORT . ';dbname=' . XS_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, XS_DB_USER, XS_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Runs $fn and turns a duplicate-key PDOException into a friendly RuntimeException. */
function xs_db_catch_duplicate($fn, $friendlyMessage)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException($friendlyMessage);
        throw $e;
    }
}

// =====================================================================
//  companies / users: admin.php logins. Each user belongs to exactly one
//  company; a company can have several users. username is globally unique.
// =====================================================================

/** User row by username (any company), or null. Used for login — the row itself tells us
 *  which company the user belongs to; a login form never asks for a company. */
function xs_db_find_user_by_username($username)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** User row by id, or null. Used to re-check the signed-in user's own current password. */
function xs_db_find_user_by_id($userId)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 1 year from now, as a DATETIME string — a 'user' role's expires_at, set once at creation.
 *  Admins pass null instead (see xs_expiry_for_role()) and never expire. */
function xs_one_year_from_now()
{
    return date('Y-m-d H:i:s', strtotime('+1 year'));
}

/** expires_at to use for a new user of this role: 1 year out for 'user', null (never expires)
 *  for 'admin'. */
function xs_expiry_for_role($role)
{
    return $role === 'admin' ? null : xs_one_year_from_now();
}

/** True once a user's expires_at has passed. A null expires_at (admins) never expires.
 *  Checked at login and again on every request after, so a session that outlives its user's
 *  expiry gets signed out automatically. */
function xs_user_is_expired(array $user)
{
    return !empty($user['expires_at']) && strtotime($user['expires_at']) <= time();
}

/** Creates a new company with this name, plus its first user (role 'admin', never expires)
 *  and a default site_settings row, all in one transaction.
 *  Returns ['companyId' => int, 'userId' => int]. */
function xs_db_register_company($companyName, $username, $passwordHash, $email = null)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    return xs_db_catch_duplicate(function () use ($pdo, $companyName, $username, $passwordHash, $email) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO companies (name) VALUES (?)')->execute([$companyName]);
            $companyId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO users (company_id, username, email, password_hash, role, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$companyId, $username, $email, $passwordHash, 'admin', xs_expiry_for_role('admin')]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO site_settings (company_id) VALUES (?)')->execute([$companyId]);
            $pdo->commit();
            return ['companyId' => $companyId, 'userId' => $userId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }, 'That username is already taken.');
}

/** Adds another user to an existing company (admin only — enforced by the caller). A 'user'
 *  role expires in a year; an 'admin' role never expires. */
function xs_db_create_user($companyId, $username, $passwordHash, $role)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    xs_db_catch_duplicate(function () use ($pdo, $companyId, $username, $passwordHash, $role) {
        $pdo->prepare('INSERT INTO users (company_id, username, password_hash, role, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$companyId, $username, $passwordHash, $role, xs_expiry_for_role($role)]);
    }, 'That username is already taken.');
}

/** Every user belonging to one company, for the admin Users panel. */
function xs_db_list_company_users($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return [];
    $stmt = $pdo->prepare('SELECT id, username, role, status, expires_at, created_at FROM users WHERE company_id = ? ORDER BY created_at ASC');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

/** Changes one user's own password by their user id. */
function xs_db_update_user_password($userId, $hash)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
}

/** Sets (or clears, with null) the email a user's "forgot password" link gets sent to. */
function xs_db_update_user_email($userId, $email)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
}

/** Stores a "forgot password" link's token (as its sha256 hash, never the raw token) and when
 *  it stops working. A fresh request overwrites any previous one, so only the latest link works. */
function xs_db_set_reset_token($userId, $tokenHash, $expiresAt)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?')
        ->execute([$tokenHash, $expiresAt, $userId]);
}

/** The user a still-valid (unexpired) reset token belongs to, or null. $tokenHash is the
 *  sha256 of the token from the emailed link — the raw token is never stored or queried for. */
function xs_db_find_user_by_reset_token($tokenHash)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires > NOW()');
    $stmt->execute([$tokenHash]);
    return $stmt->fetch() ?: null;
}

/** Invalidates a user's reset link (used right after it's been used, so it can't be replayed). */
function xs_db_clear_reset_token($userId)
{
    $pdo = xs_db();
    if (!$pdo) return;
    $pdo->prepare('UPDATE users SET reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?')->execute([$userId]);
}

/** Sets a user's one allowed device-login token (a fresh login elsewhere overwrites this,
 *  which is how the previous device gets signed out — see xs_device_session_is_current()). */
function xs_db_set_device_session_token($userId, $token)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET device_session_token = ? WHERE id = ?')->execute([$token, $userId]);
}

/** How many active admins a company has — used to stop the last one from being deactivated. */
function xs_db_count_active_admins($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'admin' AND status = 'active'");
    $stmt->execute([$companyId]);
    return (int) $stmt->fetchColumn();
}

/** One company's user by id, or null — scoped so one company can never look up another's user
 *  (used before emailing a reset notice, so a guessed userId can't be used to spam a stranger). */
function xs_db_find_company_user($companyId, $userId)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ?');
    $stmt->execute([$userId, $companyId]);
    return $stmt->fetch() ?: null;
}

/** Resets one user's password — scoped to $companyId so one company can never touch another's user. */
function xs_db_reset_user_password($companyId, $userId, $hash)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?')->execute([$hash, $userId, $companyId]);
}

/** Activates/deactivates a user — scoped to $companyId so one company can never touch another's user. */
function xs_db_set_user_status($companyId, $userId, $status)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ? AND company_id = ?')->execute([$status, $userId, $companyId]);
}

/** The company's own row (name/status), or null. */
function xs_db_get_company($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    return $stmt->fetch() ?: null;
}

// =====================================================================
//  frames table: one row per slide, scoped to its company. Everything
//  below maps between that row shape (snake_case columns) and the slide-
//  array shape used by NexShow.php / admin.php (camelCase keys).
// =====================================================================

define('XS_FRAME_DIR', 'images/Frame'); // web-relative; where frame photos live

/** camelCase slide key => frames table column. `id` and `sort_order` are handled separately. */
function xs_frame_column_map()
{
    return [
        'image' => 'image',
        'mobileImage' => 'mobile_image',
        'alt' => 'alt',
        'eyebrow' => 'eyebrow',
        'title' => 'title',
        'description' => 'description',
        'tableData' => 'table_data',
        'buttonText' => 'button_text',
        'buttonUrl' => 'button_url',
        'openInNewTab' => 'open_new_tab',
        'textAlign' => 'text_align',
        'verticalAlign' => 'vertical_align',
        'offsetX' => 'offset_x',
        'offsetY' => 'offset_y',
        'overlay' => 'overlay',
        'overlayStrength' => 'overlay_strength',
        'overlayDirection' => 'overlay_direction',
        'focalPoint' => 'focal_point',
        'mobileFocalPoint' => 'mobile_focal_point',
        'textColor' => 'text_color',
        'fontFamily' => 'font_family',
        'titleSize' => 'title_size',
        'titleWeight' => 'title_weight',
        'kenBurns' => 'ken_burns',
        'transition' => 'transition',
        'duration' => 'duration_ms',
    ];
}

/** DB row (assoc, snake_case) -> slide array (camelCase). NULL columns are left out so defaults apply. */
function xs_db_row_to_frame(array $row)
{
    $out = ['id' => $row['id']];
    foreach (xs_frame_column_map() as $key => $col) {
        if (!array_key_exists($col, $row) || $row[$col] === null) continue;
        $v = $row[$col];
        if (in_array($key, ['openInNewTab', 'overlay', 'kenBurns'], true)) $v = (bool) $v;
        elseif ($key === 'overlayStrength') $v = (float) $v;
        elseif ($key === 'titleWeight' || $key === 'duration') $v = (int) $v;
        $out[$key] = $v;
    }
    return $out;
}

/** Slide array (camelCase) -> column => value for every mapped column (missing keys become NULL). */
function xs_db_frame_params(array $frame)
{
    $out = [];
    foreach (xs_frame_column_map() as $key => $col) {
        if (!array_key_exists($key, $frame)) {
            $out[$col] = null;
            continue;
        }
        $v = $frame[$key];
        if (is_bool($v)) $v = $v ? 1 : 0;
        $out[$col] = $v === '' && in_array($key, ['mobileImage', 'mobileFocalPoint', 'fontFamily', 'titleSize'], true) ? null : $v;
    }
    return $out;
}

/** A cheap "has anything changed" fingerprint for one company's frames — count + latest update
 *  time, as a single string. Used by the live show to notice new/edited/reordered/deleted
 *  frames without re-querying or re-rendering the whole list every poll. */
function xs_db_frames_signature($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MAX(updated_at) AS u FROM frames WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    return $row['n'] . ':' . $row['u'];
}

/** All of one company's frames, in display order. Empty array if the database is unreachable or has none. */
function xs_db_list_frames($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return [];
    try {
        $stmt = $pdo->prepare('SELECT * FROM frames WHERE company_id = ? ORDER BY sort_order ASC, created_at ASC');
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return array_map('xs_db_row_to_frame', $rows);
}

/** One frame by id within a company, or null if it doesn't exist / the database is unreachable. */
function xs_db_get_frame($companyId, $id)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM frames WHERE company_id = ? AND id = ?');
    $stmt->execute([$companyId, $id]);
    $row = $stmt->fetch();
    return $row ? xs_db_row_to_frame($row) : null;
}

/** Inserts a new frame row for a company. Throws RuntimeException if the database is unreachable. */
function xs_db_insert_frame($companyId, $id, array $frame, $sortOrder)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $cols = xs_db_frame_params($frame);
    $cols['company_id'] = $companyId;
    $cols['id'] = $id;
    $cols['sort_order'] = $sortOrder;
    $fields = array_keys($cols);
    $sql = 'INSERT INTO frames (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($cols));
}

/** Updates every mapped column of an existing frame row, scoped to its company. */
function xs_db_update_frame($companyId, $id, array $frame)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $cols = xs_db_frame_params($frame);
    $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($cols)));
    $stmt = $pdo->prepare("UPDATE frames SET $sets WHERE company_id = ? AND id = ?");
    $stmt->execute(array_merge(array_values($cols), [$companyId, $id]));
}

/** Deletes a frame row (scoped to its company). Does not touch image files — see xs_delete_frame_files(). */
function xs_db_delete_frame($companyId, $id)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('DELETE FROM frames WHERE company_id = ? AND id = ?')->execute([$companyId, $id]);
}

/** Re-numbers one company's sort_order to 0..n-1 following the given id order. */
function xs_db_renumber($companyId, array $orderedIds)
{
    $pdo = xs_db();
    if (!$pdo) return;
    $stmt = $pdo->prepare('UPDATE frames SET sort_order = ? WHERE company_id = ? AND id = ?');
    foreach (array_values($orderedIds) as $i => $id) $stmt->execute([$i, $companyId, $id]);
}

/** True for a path this app manages inside images/Frame — safe to delete, unlike embedded or external images. */
function xs_is_frame_file($path)
{
    $path = (string) $path;
    return $path !== '' && strpos($path, XS_FRAME_DIR . '/') === 0 && strpos($path, '..') === false;
}

/** True if a path's extension marks it as a video rather than an image — `image` can hold either. */
function xs_is_video_path($path)
{
    return (bool) preg_match('/\.(mp4|webm|mov|m4v)$/i', (string) $path);
}

/** Deletes a frame's own image files from images/Frame (never touches embedded or external images). */
function xs_delete_frame_files(array $frame)
{
    foreach (['image', 'mobileImage'] as $key) {
        $path = $frame[$key] ?? '';
        if (xs_is_frame_file($path)) @unlink(__DIR__ . '/' . $path);
    }
}

// =====================================================================
//  site_settings table: one row per company — slider settings + the
//  device-menu login. (The admin.php login lives in `users`, not here.)
// =====================================================================

/** camelCase settings key => site_settings column. */
function xs_settings_column_map()
{
    return [
        'pageTitle' => 'page_title',
        'ariaLabel' => 'aria_label',
        'autoplay' => 'autoplay',
        'pauseOnHover' => 'pause_on_hover',
        'duration' => 'duration_ms',
        'transition' => 'transition',
        'transitionMs' => 'transition_ms',
        'swipeThreshold' => 'swipe_threshold',
        'mobileBreakpoint' => 'mobile_breakpoint',
        'orientation' => 'orientation',
    ];
}

/**
 * One company's settings row, shaped like the old xshow-data.php: ['settings' => [...]].
 * Returns null if the database is unreachable or the row doesn't exist yet.
 */
function xs_db_get_settings($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM site_settings WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    $settings = [];
    foreach (xs_settings_column_map() as $key => $col) {
        $v = $row[$col];
        if (in_array($key, ['autoplay', 'pauseOnHover'], true)) $v = (bool) $v;
        elseif (in_array($key, ['duration', 'transitionMs', 'swipeThreshold', 'mobileBreakpoint'], true)) $v = (int) $v;
        $settings[$key] = $v;
    }
    return ['settings' => $settings];
}

/** Creates or updates a company's settings row. */
function xs_db_save_settings($companyId, array $settings)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $cols = [];
    foreach (xs_settings_column_map() as $key => $col) {
        if (!array_key_exists($key, $settings)) continue;
        $v = $settings[$key];
        if (is_bool($v)) $v = $v ? 1 : 0;
        $cols[$col] = $v;
    }
    $fields = array_keys($cols);
    $placeholders = array_fill(0, count($fields), '?');
    $updates = implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $fields));
    $sql = 'INSERT INTO site_settings (company_id, ' . implode(', ', $fields) . ') VALUES (?, ' . implode(', ', $placeholders) . ')'
         . ' ON DUPLICATE KEY UPDATE ' . $updates;
    $pdo->prepare($sql)->execute(array_merge([$companyId], array_values($cols)));
}

// =====================================================================
//  Schema-derived defaults. Rather than keeping a second, hand-written
//  copy of each column's default value in PHP, these read it straight
//  off the table definition — the database stays the one place that
//  value is set. Both return null if the database is unreachable, so
//  callers can fall back to a hardcoded emergency default of their own.
//  Not company-scoped: a column's default is the same for every company.
// =====================================================================

/** column => raw default value (string) or null, straight from the table definition. */
function xs_db_column_defaults($table)
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $pdo = xs_db();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([XS_DB_NAME, $table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) $out[$row['COLUMN_NAME']] = $row['COLUMN_DEFAULT'];
    } catch (Throwable $e) {
        return $cache[$table] = null;
    }
    return $cache[$table] = $out;
}

/** $slider-shaped array (autoplay, duration, transition, …) read from site_settings' column defaults. */
function xs_db_slider_defaults()
{
    $cols = xs_db_column_defaults('site_settings');
    if ($cols === null) return null;
    $out = [];
    foreach (xs_settings_column_map() as $key => $col) {
        if ($key === 'pageTitle') continue; // tracked separately, not part of $slider
        $v = $cols[$col] ?? null;
        if (in_array($key, ['autoplay', 'pauseOnHover'], true)) $v = (bool) $v;
        elseif (in_array($key, ['duration', 'transitionMs', 'swipeThreshold', 'mobileBreakpoint'], true)) $v = (int) $v;
        $out[$key] = $v;
    }
    return $out;
}

/** $slideDefaults-shaped array (textAlign, overlay, focalPoint, …) read from frames' column defaults. */
function xs_db_frame_defaults()
{
    $cols = xs_db_column_defaults('frames');
    if ($cols === null) return null;
    $keys = [
        'textAlign', 'verticalAlign', 'offsetX', 'offsetY', 'overlay', 'overlayStrength', 'overlayDirection',
        'focalPoint', 'mobileFocalPoint', 'textColor', 'fontFamily', 'titleSize', 'titleWeight', 'kenBurns', 'openInNewTab',
    ];
    $map = xs_frame_column_map();
    $out = [];
    foreach ($keys as $key) {
        $v = $cols[$map[$key]] ?? null;
        if ($v !== null) {
            if (in_array($key, ['overlay', 'kenBurns', 'openInNewTab'], true)) $v = (bool) $v;
            elseif ($key === 'overlayStrength') $v = (float) $v;
            elseif ($key === 'titleWeight') $v = (int) $v;
        }
        $out[$key] = $v;
    }
    return $out;
}

// =====================================================================
//  tags table: named values usable as {{name}} placeholders in a
//  frame's table cells (see xs_apply_tags), scoped per company.
// =====================================================================

/** All of one company's tags as name => value. Empty array if unreachable or none exist. */
function xs_db_list_tags($companyId)
{
    $pdo = xs_db();
    if (!$pdo) return [];
    try {
        $stmt = $pdo->prepare('SELECT name, value FROM tags WHERE company_id = ? ORDER BY name ASC');
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) $out[$row['name']] = $row['value'];
    return $out;
}

/** Creates or updates a tag within one company. */
function xs_db_save_tag($companyId, $name, $value)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $sql = 'INSERT INTO tags (company_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
    $pdo->prepare($sql)->execute([$companyId, $name, $value]);
}

function xs_db_delete_tag($companyId, $name)
{
    $pdo = xs_db();
    if (!$pdo) throw new RuntimeException('Could not reach the database.');
    $pdo->prepare('DELETE FROM tags WHERE company_id = ? AND name = ?')->execute([$companyId, $name]);
}

/** Replaces every {{name}} in $text with that tag's value (left as-is if the tag doesn't exist). */
function xs_apply_tags($text, array $tags)
{
    if ($text === '' || strpos($text, '{{') === false) return $text;
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($tags) {
        return array_key_exists($m[1], $tags) ? $tags[$m[1]] : $m[0];
    }, $text);
}
