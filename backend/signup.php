<?php
// backend/signup.php - compatible with your current _intro.php
include_once("../_intro.php");
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
@ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = (string)($input['password'] ?? '');
$csrf_in = $input['csrf_token'] ?? '';
$session_csrf = $_SESSION['csrftokken'] ?? $_SESSION['csrf_token'] ?? null;

function send_json_local($payload, $status = 200){ while (ob_get_level()) ob_end_clean(); http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload); exit; }

$response = ['status'=>'error','fieldErrors'=>[],'globalError'=>''];

// CSRF
if (empty($session_csrf) || empty($csrf_in) || !hash_equals($session_csrf, $csrf_in)) {
    $response['globalError'] = 'Invalid CSRF token.';
    send_json_local($response, 400);
}

// validations
if ($username === '') $response['fieldErrors']['signup-username'] = 'Please enter a username.';
elseif (!preg_match('/^[a-zA-Z0-9._]{2,30}$/', $username) || !preg_match('/^[a-zA-Z0-9].*[a-zA-Z0-9]$/', $username) || preg_match('/[_.]{2,}/', $username)) $response['fieldErrors']['signup-username'] = 'Invalid username format.';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $response['fieldErrors']['signup-email'] = 'Please enter a valid e-mail address.';

if ($password === '') $response['fieldErrors']['signup-password'] = 'Please enter a password.';
elseif (strlen($password) < 10) $response['fieldErrors']['signup-password'] = 'Password must be at least 10 characters long.';
elseif (strlen($password) > 64) $response['fieldErrors']['signup-password'] = 'Max password length is 64 characters.';
elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) $response['fieldErrors']['signup-password'] = 'Password must meet complexity requirements.';

if (!empty($response['fieldErrors'])) send_json_local($response);

// uniqueness checks
$stmt = $mysqli->prepare("SELECT 1 FROM {$dbprefix}members WHERE username = ? LIMIT 1");
if ($stmt) { $stmt->bind_param('s', $username); $stmt->execute(); $res = $stmt->get_result(); if ($res && $res->num_rows > 0) { $response['fieldErrors']['signup-username'] = 'Username already taken.'; send_json_local($response); } $stmt->close(); }
$stmt = $mysqli->prepare("SELECT 1 FROM {$dbprefix}members WHERE email = ? LIMIT 1");
if ($stmt) { $stmt->bind_param('s', $email); $stmt->execute(); $res = $stmt->get_result(); if ($res && $res->num_rows > 0) { $response['fieldErrors']['signup-email'] = 'Email already registered.'; send_json_local($response); } $stmt->close(); }

try {
    $mysqli->begin_transaction();
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $newMemRank = 71;
    $gender = 'male';
    $country = '';
    $datejoined = time();
    $profilePic = "images/avatar/defaultprofile.png";
    $thumbsPic  = "images/thumbs/defaultprofile.png";
    $verification_code = bin2hex(random_bytes(4));
    $verification_expiry = date('Y-m-d H:i:s', time() + 3600*24);

    $cols = ["username","password","rank_id","profilepic","avatar","gender","country","birthday","email","datejoined","profileviews","ipaddress","disabled","verified","verification_code","verification_expiry"];
    $vals = [$username, $password_hash, $newMemRank, $profilePic, $thumbsPic, $gender, $country, 0, $email, $datejoined, 0, $IP_ADDRESS, 1, 1, $verification_code, $verification_expiry];

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $types = '';
    foreach ($vals as $v) { if (is_int($v)) $types .= 'i'; elseif (is_float($v)) $types .= 'd'; else $types .= 's'; }

    $sql = "INSERT INTO {$dbprefix}members (" . implode(',', $cols) . ") VALUES ({$placeholders})";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception("DB prepare failed: " . $mysqli->error);

    $bindParams = array_merge([$types], $vals);
    $refs = [];
    foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];
    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) throw new Exception("bind_param failed: " . $stmt->error);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    $insertedId = $stmt->insert_id ?? $mysqli->insert_id;
    $stmt->close();
    $mysqli->commit();

    // Verification disabled temperarelly
	// set session email to allow verify page to work (no changes to _intro.php needed)
    // $_SESSION['email'] = $email;
	// send_json_local(['status'=>'verify','redirect'=>MAIN_ROOT . 'verify.php','message'=>'Account created. Please verify your email.']);
	
	$_SESSION['user_id'] = $insertedId;
	
	session_regenerate_id(true);
	$_SESSION['user_id'] = (int)$insertedId;
	$_SESSION['RememberMe'] = 0;
	$wsToken = bin2hex(random_bytes(32));
	$_SESSION['ws_token'] = $wsToken;

	// update ip and ws_token in DB (best-effort)
	$ip = substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 0, 255);
	$u = $mysqli->prepare("UPDATE {$dbprefix}members SET ipaddress = ?, ws_token = ? WHERE member_id = ?");
	if ($u) { $u->bind_param('ssi', $ip, $wsToken, $row['member_id']); $u->execute(); $u->close(); }
	
	// remember-me (best-effort; table optional)
	// if ($rememberme === 1) {
		// if ($mysqli->query("SHOW TABLES LIKE 'remember_me_tokens'")->num_rows) {
			// $selector = bin2hex(random_bytes(8));
			// $token = bin2hex(random_bytes(33));
			// $token_hash = hash('sha256', $token);
			// $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
			// $ins = $mysqli->prepare("INSERT INTO {$dbprefix}remember_me_tokens (selector, token_hash, member_id, expires) VALUES (?, ?, ?, ?)");
			// if ($ins) { $ins->bind_param('ssis', $selector, $token_hash, $row['member_id'], $expires); $ins->execute(); $ins->close(); setcookie('rememberme', $selector . ':' . $token, time() + 86400 * 30, '/', '', isset($_SERVER['HTTPS']), true); }
		// }
	// }
	
	send_json_local(['status'=>'success','redirect'=>MAIN_ROOT . '','message'=>'Account created. welcome.']);
    
} catch (Throwable $e) {
    @$mysqli->rollback();
    error_log('Signup error: ' . $e->getMessage());
    send_json_local(['status'=>'error','globalError'=>'Error creating account. Try again later.'], 500);
}
