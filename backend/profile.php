<?php
// backend/profile.php
// Unified profile endpoint: actions = avatar, username, password, socials, bio, country
// Returns JSON: { status, fieldErrors, globalError, message, ... }

include_once(__DIR__ . '/../_intro.php'); // keep your original include (has helpers)
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');

// base response
$response = [
    'status' => 'error',
    'fieldErrors' => (object)[], // will be converted to object in json
    'globalError' => '',
    'message' => ''
];

function send_json($payload, $http_code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function add_field_error(&$resp, $inputId, $msg) {
    if (!isset($resp['fieldErrors']) || !is_array($resp['fieldErrors'])) $resp['fieldErrors'] = [];
    $resp['fieldErrors'][$inputId] = $msg;
}

// parse request: multipart (avatar) uses $_POST/$_FILES, other actions use JSON body
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

$raw = file_get_contents('php://input');
$input = [];

if ($isMultipart) {
    // prefer action/csrf from POST body
    $input = $_POST;
} else {
    $input = json_decode($raw, true) ?: [];
}

// allow multiple csrf field names (csrf_token or CSRF_TOKKEN)
$csrf_in = $input['csrf_token'] ?? $input['CSRF_TOKKEN'] ?? $input['CSRF'] ?? '';

// canonical session CSRF token
$session_csrf = $_SESSION['csrftokken'] ?? $_SESSION['csrf_token'] ?? null;

// must be logged in
if (!isset($_SESSION['user_id'])) {
    $response['globalError'] = 'Not logged in.';
    send_json($response, 401);
}
$user_id = (int) $_SESSION['user_id'];

// simple CSRF check
if (empty($session_csrf) || empty($csrf_in) || !hash_equals((string)$session_csrf, (string)$csrf_in)) {
    $response['globalError'] = 'Invalid CSRF token.';
    send_json($response, 403);
}

// action required
$action = trim($input['action'] ?? ($isMultipart ? ($_POST['action'] ?? '') : ''));
if ($action === '') {
    $response['globalError'] = 'No action specified.';
    send_json($response, 400);
}

// per-action rate limits (kept close to your originals)
$rate_limits = [
    'avatar'   => 10,
    'username' => 5,
    'bio'      => 5,
    'country'  => 3,
    'socials'  => 5,
    'password' => 7
];
$limit = $rate_limits[$action] ?? 5;

// check rate limit from request_logs (your DB)
$stmt = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM request_logs WHERE user_id = ? AND type = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
if ($stmt) {
    $stmt->bind_param('is', $user_id, $action);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $count = (int)($row['cnt'] ?? 0);
    $stmt->close();
    if ($count >= $limit) {
        send_json(['status' => 'tooMany', 'message' => 'Too many requests. Please wait a minute.'], 429);
    }
}

// log request (best-effort)
@$mysqli->query("INSERT INTO request_logs (user_id, type) VALUES (" . intval($user_id) . ", '" . $mysqli->real_escape_string($action) . "')");

// Now switch actions and delegate to your existing helpers (do NOT reimplement)
switch ($action) {

    // ---------------- AVATAR ----------------
    case 'avatar':
        // expect multipart with file in 'image'
        if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            add_field_error($response, 'fileUpload', 'Please provide an image.');
            send_json($response, 400);
        }

        // IMPORTANT: use validate_image_upload() from _intro.php (per your request)
        // It should return an array like your previous code expected ($result['status'] etc).
        // We will call it and respect its output.
        if (!function_exists('validate_image_upload')) {
            $response['globalError'] = 'Server configuration error: validate_image_upload() not available.';
            send_json($response, 500);
        }

        // Determine upload directory (mirror previous backend)
        $uploadDir = realpath(__DIR__ . '/../images/avatar/');
        if ($uploadDir === false) {
            // fallback: build path relative to BASE_DIRECTORY if provided
            $baseDir = rtrim(BASE_DIRECTORY ?? (__DIR__ . '/..'), '/');
            $uploadDir = $baseDir . '/images/avatar/';
        }

        // call helper (it handles validation and file saving)
        $result = validate_image_upload($_FILES['image'], $uploadDir, "avatar_");

        if (!is_array($result) || !isset($result['status'])) {
            $response['globalError'] = 'Invalid image upload result from server helper.';
            send_json($response, 500);
        }

        if ($result['status'] === 'success') {
            // expected keys from your previous code: original and thumb (paths)
            $original = $result['original'] ?? null;
            $thumb = $result['thumb'] ?? null;

            // update DB (use same columns as your edit_avatar.php)
            $stmt = $mysqli->prepare("SELECT profilepic, avatar FROM {$dbprefix}members WHERE member_id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $old = $res && $res->num_rows ? $res->fetch_assoc() : null;
            $stmt->close();

            $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET profilepic = ?, avatar = ? WHERE member_id = ?");
            $stmt->bind_param('ssi', $original, $thumb, $user_id);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                // best-effort delete old files (preserve your previous behavior)
                if ($old) {
                    if (!empty($old['avatar']) && $old['avatar'] !== 'images/thumbs/defaultprofile.png') {
                        @unlink(BASE_DIRECTORY . $old['avatar']);
                    }
                    if (!empty($old['profilepic']) && $old['profilepic'] !== 'images/avatar/defaultprofile.png') {
                        @unlink(BASE_DIRECTORY . $old['profilepic']);
                    }
                }

                $response['status'] = 'success';
                $response['message'] = $result['msg'] ?? 'Avatar updated successfully!';
                if (!empty($original)) $response['new_profilepic'] = $original;
                if (!empty($thumb)) $response['thumb'] = $thumb;
                send_json($response);
            } else {
                $response['globalError'] = 'Database update failed.';
                send_json($response, 500);
            }
        } else {
            // validation failed according to your helper: forward message as field error or globalError
            $errMsg = $result['msg'] ?? 'Image validation failed.';
            add_field_error($response, 'fileUpload', $errMsg);
            send_json($response, 400);
        }
        break;

    // ---------------- USERNAME ----------------
    case 'username':
        $username = trim($input['username'] ?? '');
        // reuse your original validation logic but return fieldErrors instead of plc/inp/bor
        if (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
            add_field_error($response, 'username', 'Username can only contain letters, numbers, dots, and underscores.');
            send_json($response, 400);
        }
        if (strlen($username) < 2 || strlen($username) > 30) {
            add_field_error($response, 'username', 'Username must be 2–30 characters long.');
            send_json($response, 400);
        }
        if (!preg_match('/^[a-zA-Z0-9].*[a-zA-Z0-9]$/', $username)) {
            add_field_error($response, 'username', 'Username must start and end with a letter or number.');
            send_json($response, 400);
        }
        if (preg_match('/[_.]{2,}/', $username)) {
            add_field_error($response, 'username', 'Username cannot contain consecutive dots or underscores.');
            send_json($response, 400);
        }
        if ($username === '') {
            add_field_error($response, 'username', 'Please enter a username.');
            send_json($response, 400);
        }
        if (strlen($username) < 4) {
            add_field_error($response, 'username', 'Username is too short.');
            send_json($response, 400);
        }
        if (strlen($username) > 50) {
            add_field_error($response, 'username', 'Username is too long.');
            send_json($response, 400);
        }

        // uniqueness check excluding current user
		$stmt = $mysqli->prepare("SELECT member_id FROM {$dbprefix}members WHERE username = ? AND member_id != ? LIMIT 1");
		$stmt->bind_param('si', $username, $user_id);
		$stmt->execute();
		$res = $stmt->get_result();
		$exists = ($res && $res->num_rows > 0);
		$stmt->close();
		if ($exists) {
			add_field_error($response, 'username', 'This username is already taken.');
			send_json($response, 409);
		}

        // update
        $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET username = ? WHERE member_id = ?");
        $stmt->bind_param('si', $username, $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $response['status'] = 'success';
            $response['message'] = 'Username has been updated successfully.';
            send_json($response);
        } else {
            $response['globalError'] = 'Database error.';
            send_json($response, 500);
        }
        break;

    // ---------------- BIO ----------------
    case 'bio':
        $bio = trim($input['bio'] ?? '');
        $max_length = 500;
        if (strlen($bio) > $max_length) {
            add_field_error($response, 'bio', 'Bio is too long (max ' . $max_length . ' characters).');
            send_json($response, 400);
        }
        // delegate content checks to your existing logic if you have helpers — else basic regex check
        // but per your instruction, keep checks minimal and rely on _intro if available

        $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET bio = ? WHERE member_id = ?");
        $stmt->bind_param('si', $bio, $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $response['status'] = 'success';
            $response['message'] = 'Bio updated successfully!';
            send_json($response);
        } else {
            add_field_error($response, 'bio', 'Database error. Please try again.');
            send_json($response, 500);
        }
        break;

    // ---------------- COUNTRY ----------------
    case 'country':
        $country = trim($input['country'] ?? '');
        // allowed list should match front-end list
        $allowed = [
            '' => 'Not set',
            'US'=>'United States','GB'=>'United Kingdom','CA'=>'Canada','DE'=>'Germany','FR'=>'France',
            'ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','AU'=>'Australia',
            'IN'=>'India','JP'=>'Japan','CN'=>'China','DZ'=>'Algeria','MA'=>'Morocco','EG'=>'Egypt'
        ];
        if (!array_key_exists($country, $allowed)) {
            add_field_error($response, 'countryInput', 'Invalid country');
            send_json($response, 400);
        }
        $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET country = ? WHERE member_id = ?");
        $stmt->bind_param('si', $country, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            $response['globalError'] = 'Failed to save country';
            send_json($response, 500);
        }

        // optional flag url (same approach as your previous code)
        $flag_rel_path = 'assets/images/flags/' . $country . '.png';
        $flag_full_path = __DIR__ . '/../' . $flag_rel_path;
        $flag_url = '';
        if ($country !== '' && file_exists($flag_full_path)) {
            $flag_url = (isset($MAIN_ROOT) ? rtrim($MAIN_ROOT, '/') . '/' : '/') . $flag_rel_path;
        }

        $response['status'] = 'success';
        $response['message'] = 'Country updated';
        $response['country'] = $country;
        $response['country_name'] = $allowed[$country] ?? '';
        if ($flag_url) $response['flag_url'] = $flag_url;
        send_json($response);
        break;

    // ---------------- SOCIALS ----------------
    case 'socials':
        $facebook = trim($input['facebook'] ?? '');
        $twitch = trim($input['twitch'] ?? '');
        $youtube = trim($input['youtube'] ?? '');

        // helper to extract handles (kept same semantics as your original code)
        $extract_handle = function($s) {
            $s = trim($s);
            if ($s === '') return null;
            if (stripos($s, 'http') === 0 || stripos($s, 'www.') === 0) {
                $u = @parse_url($s);
                if ($u && isset($u['path'])) {
                    $path = trim($u['path'], '/');
                    if ($path === '') return null;
                    $parts = explode('/', $path);
                    return end($parts);
                } else {
                    return null;
                }
            }
            return ltrim($s, '@');
        };

        $facebook_h = $facebook !== '' ? $extract_handle($facebook) : null;
        $twitch_h = $twitch !== '' ? $extract_handle($twitch) : null;
        $youtube_h = $youtube !== '' ? $extract_handle($youtube) : null;

        // validations (same rules as your originals)
        if ($twitch_h !== null && !preg_match('/^[a-z0-9_]{4,25}$/i', $twitch_h)) {
            add_field_error($response, 'twitch', 'Invalid Twitch username');
            send_json($response, 400);
        }
        if ($facebook_h !== null && !preg_match('/^[A-Za-z0-9\.]{3,100}$/', $facebook_h)) {
            add_field_error($response, 'facebook', 'Invalid Facebook handle');
            send_json($response, 400);
        }
        if ($youtube_h !== null) {
            if (strpos($youtube_h, 'UC') === 0) {
                if (!preg_match('/^UC[0-9A-Za-z_\-]{22}$/', $youtube_h)) {
                    add_field_error($response, 'youtube', 'Invalid YouTube channel ID');
                    send_json($response, 400);
                }
            } else {
                if (!preg_match('/^[A-Za-z0-9_\-]{2,100}$/', $youtube_h)) {
                    add_field_error($response, 'youtube', 'Invalid YouTube handle');
                    send_json($response, 400);
                }
            }
        }

        // store null for empty
        $facebook_db = $facebook_h !== null ? $facebook_h : null;
        $twitch_db = $twitch_h !== null ? $twitch_h : null;
        $youtube_db = $youtube_h !== null ? $youtube_h : null;

        $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET facebook = ?, twitch = ?, youtube = ? WHERE member_id = ?");
        $stmt->bind_param('sssi', $facebook_db, $twitch_db, $youtube_db, $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $response['status'] = 'success';
            $response['message'] = 'Social links updated';
            send_json($response);
        } else {
            add_field_error($response, 'facebook', 'Database error');
            send_json($response, 500);
        }
        break;

    // ---------------- PASSWORD ----------------
    case 'password':
        $current_pass = $input['current_pass'] ?? '';
        $new_pass = $input['new_pass'] ?? '';

        if ($current_pass === '') {
            add_field_error($response, 'password', 'Please enter your current password.');
            send_json($response, 400);
        }

        // fetch stored password (same as your previous code)
        $stmt = $mysqli->prepare("SELECT password, password2 FROM {$dbprefix}members WHERE member_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res && $res->num_rows ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $response['globalError'] = 'Account not found.';
            send_json($response, 404);
        }

        $realPassword = $row['password'];
		// No need for salt anymore - it's embedded in the hash

		if (!password_verify($current_pass, $realPassword)) {
			add_field_error($response, 'password', 'You entered an incorrect current password.');
			// send_json($response, 400);
			send_json($response);
		}

        if ($new_pass === '') {
            add_field_error($response, 'newPassword', 'Please enter a new password.');
            send_json($response, 400);
        }
        if (strlen($new_pass) < 10) {
            add_field_error($response, 'newPassword', 'Password must be at least 10 characters long.');
            send_json($response, 400);
        }
        if (!preg_match('/[A-Z]/', $new_pass)) {
            add_field_error($response, 'newPassword', 'Password must contain at least one uppercase letter (A-Z).');
            send_json($response, 400);
        }
        if (!preg_match('/[a-z]/', $new_pass)) {
            add_field_error($response, 'newPassword', 'Password must contain at least one lowercase letter (a-z).');
            send_json($response, 400);
        }
        if (!preg_match('/[0-9]/', $new_pass)) {
            add_field_error($response, 'newPassword', 'Password must contain at least one number (0-9).');
            send_json($response, 400);
        }
        if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $new_pass)) {
            add_field_error($response, 'newPassword', 'Password must contain at least one symbol (! @ # $ % etc.).');
            send_json($response, 400);
        }
        if (strlen($new_pass) > 64) {
            add_field_error($response, 'newPassword', 'Max password length is 64 characters.');
            send_json($response, 400);
        }

        // use encryptPassword() helper from _intro.php (preferred)
        if (function_exists('encryptPassword')) {
            $passwordInfo = encryptPassword($new_pass);
            $hash = $passwordInfo['password'];
            //$salt_new = $passwordInfo['salt'];
        } else {
            // fallback (shouldn't be used if helper exists)
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $salt_new = '';
        }

        $stmt = $mysqli->prepare("UPDATE {$dbprefix}members SET password = ? WHERE member_id = ?");
        $stmt->bind_param('si', $hash, $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $response['status'] = 'success';
            $response['message'] = 'Password was updated with success.';
            send_json($response);
        } else {
            add_field_error($response, 'newPassword', 'Database error updating password.');
            send_json($response, 500);
        }
        break;

    default:
        $response['globalError'] = 'Unknown action.';
        send_json($response, 400);
        break;
}

// fallback
$response['globalError'] = 'Unhandled error';
send_json($response, 500);
