<?php
// backend/login.php - compatible with your current _intro.php
include_once("../_intro.php"); // DO NOT modify _intro.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// stop showing warnings in output (we'll still log server-side)
@ini_set('display_errors', '0');
ob_start(); // capture any stray output so we can return clean JSON

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$user = trim($input['user'] ?? '');
$pass = (string)($input['pass'] ?? '');
$rememberme = isset($input['rememberme']) && intval($input['rememberme']) === 1 ? 1 : 0;

// detect CSRF token (legacy or canonical)
$session_csrf = $_SESSION['csrftokken'] ?? $_SESSION['csrf_token'] ?? null;
$csrf_in = $input['csrf_token'] ?? '';

// helper send
function send_json($payload, $status = 200){
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$response = ['status' => 'error', 'fieldErrors' => [], 'globalError' => ''];

// CSRF check
if (empty($session_csrf) || empty($csrf_in) || !hash_equals($session_csrf, $csrf_in)) {
    $response['globalError'] = 'Invalid CSRF token.';
    send_json($response, 400);
}

// quick validation
if ($user === '') $response['fieldErrors']['login-username'] = 'Please enter your username or email.';
if ($pass === '') $response['fieldErrors']['login-password'] = 'Please enter your password.';
if (!empty($response['fieldErrors'])) send_json($response);

// lookup
$stmt = $mysqli->prepare("SELECT member_id, username, email, password, verified, disabled, deleted FROM {$dbprefix}members WHERE username = ? OR email = ? LIMIT 1");
if (!$stmt) { $response['globalError'] = 'Database error.'; send_json($response, 500); }
$stmt->bind_param('ss', $user, $user);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    $response['fieldErrors']['login-username'] = 'Invalid username or password.';
    send_json($response);
}
$row = $res->fetch_assoc();
$stmt->close();

// verify password (password_verify preferred; fallback to crypt)
$ok = false;
if (!empty($row['password'])) {
    if (password_verify($pass, $row['password'])) {
        $ok = true;
        if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $u = $mysqli->prepare("UPDATE {$dbprefix}members SET password = ? WHERE member_id = ?");
            if ($u) { $u->bind_param('si', $newHash, $row['member_id']); $u->execute(); $u->close(); }
        }
    } else {
        if (strlen($row['password']) > 3 && crypt($pass, $row['password']) === $row['password']) {
            $ok = true;
            // rehash to password_hash
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $u = $mysqli->prepare("UPDATE {$dbprefix}members SET password = ? WHERE member_id = ?");
            if ($u) { $u->bind_param('si', $newHash, $row['member_id']); $u->execute(); $u->close(); }
        }
    }
}
if (!$ok) { $response['fieldErrors']['login-password'] = 'Invalid username or password.'; send_json($response); }

// account checks
if ((int)$row['deleted'] === 1) { $response['globalError'] = 'Account scheduled for deletion.'; send_json($response); }

if ((int)$row['verified'] !== 1) {
    $_SESSION['email'] = $row['email'];
    send_json(['status'=>'verify', 'redirect'=>MAIN_ROOT . 'verify.php']);
}

if ((int)$row['disabled'] === 1) { $response['globalError'] = 'Account disabled.'; send_json($response); }

// success
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$row['member_id'];
$_SESSION['RememberMe'] = $rememberme ? 1 : 0;
$wsToken = bin2hex(random_bytes(32));
$_SESSION['ws_token'] = $wsToken;

// update ip and ws_token in DB (best-effort)
$ip = substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 0, 255);
$u = $mysqli->prepare("UPDATE {$dbprefix}members SET ipaddress = ?, ws_token = ? WHERE member_id = ?");
if ($u) { $u->bind_param('ssi', $ip, $wsToken, $row['member_id']); $u->execute(); $u->close(); }

// remember-me (best-effort; table optional)
if ($rememberme === 1) {
    if ($mysqli->query("SHOW TABLES LIKE 'remember_me_tokens'")->num_rows) {
        $selector = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(33));
        $token_hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
        $ins = $mysqli->prepare("INSERT INTO {$dbprefix}remember_me_tokens (selector, token_hash, member_id, expires) VALUES (?, ?, ?, ?)");
        if ($ins) { $ins->bind_param('ssis', $selector, $token_hash, $row['member_id'], $expires); $ins->execute(); $ins->close(); setcookie('rememberme', $selector . ':' . $token, time() + 86400 * 30, '/', '', isset($_SERVER['HTTPS']), true); }
    }
}

send_json(['status'=>'success','redirect'=>MAIN_ROOT]);
