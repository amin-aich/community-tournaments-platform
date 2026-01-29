<?php
// backend/load_notifications.php
header('Content-Type: application/json');
include_once("../_intro.php"); // adjust path if needed

// Read raw input (allow empty body)
$raw = file_get_contents('php://input');
$input = [];
if ($raw !== false && strlen(trim($raw)) > 0) {
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['error' => 'bad_request', 'msg' => 'invalid_json']);
        exit;
    }
    $input = (array)$decoded;
}

// Use session user (notifications.php requires session)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
$userID = (int) $_SESSION['user_id'];

$limit = (int)($input['limit'] ?? 5);
$last_created_at = $input['last_created_at'] ?? null;
$last_notif_id = isset($input['last_notif_id']) ? (int)$input['last_notif_id'] : null;

// sanity
if ($limit <= 0 || $limit > 100) $limit = 20;

// Build keyset SQL (same ordering as notifications.php)
$sql = "SELECT id, target_user_id, type, payload, seen, created_at
        FROM {$dbprefix}notifications
        WHERE target_user_id = ? ";
$params = [$userID];
$types = 'i';

if (!empty($last_created_at) && $last_notif_id !== null) {
    $sql .= " AND (created_at < ? OR (created_at = ? AND id < ?)) ";
    $params[] = $last_created_at;
    $params[] = $last_created_at;
    $params[] = $last_notif_id;
    $types .= 'ssi';
}

$sql .= " ORDER BY created_at DESC, id DESC LIMIT ? ";
$params[] = $limit + 1; // fetch one extra to detect has_more
$types .= 'i';

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['error' => 'prepare_failed', 'details' => $mysqli->error]);
    exit;
}

// bind params dynamically
$bind_names = [];
$bind_names[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind = 'bind' . $i;
    $$bind = $params[$i];
    $bind_names[] = &$$bind;
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

// Determine has_more by extra row
$has_more = false;
if (count($rows) > $limit) {
    $has_more = true;
    array_pop($rows);
}

// Helper to build avatar url (mirrors notifications.php behavior)
function avatar_url_for_backend($avatar_path) {
    global $MAIN_ROOT;
    if (empty($avatar_path)) return $MAIN_ROOT . "assets/images/notification.png";
    if (strpos($avatar_path, 'http://') === 0 || strpos($avatar_path, 'https://') === 0) return $avatar_path;
    return $MAIN_ROOT . ltrim($avatar_path, '/');
}

// Build HTML for each returned notification row using same markup as notifications.php
ob_start();
foreach ($rows as $notification) {
    $payload = json_decode($notification['payload'], true);
    if (!is_array($payload)) $payload = [];

    $seen = (int)$notification['seen'] === 1;
    $seen_class = $seen ? '' : 'unseen';

    $created_at = strtotime($notification['created_at']);
    $time_ago = function_exists('gettimeago') ? gettimeago($created_at) : date('Y-m-d H:i', $created_at);

    $avatar_url = $MAIN_ROOT . 'assets/images/notification.png';
    $overlay_img = $MAIN_ROOT . 'assets/images/notification.png'; // default overlay same as original (notifications.php used overlay_img variable there)

    $action = $notification['type'] ?? 'system';
    if ($action === "system") {
        $avatar_url = $MAIN_ROOT . "assets/images/settings.png";
    } elseif ($action === "tournament") {
        $avatar_url = $MAIN_ROOT . "assets/images/tournament.png";
    } elseif ($action === "match") {
        if (!empty($payload['avatar_url'])) {
            $avatar_url = (strpos($payload['avatar_url'], 'http://') === 0 || strpos($payload['avatar_url'], 'https://') === 0)
                ? $payload['avatar_url']
                : $MAIN_ROOT . ltrim($payload['avatar_url'], '/');
        }
        $overlay_img = $MAIN_ROOT . "assets/images/tournament_blue.png";
    } else {
        // fallback: default notification image (keeps behavior consistent)
        $avatar_url = $MAIN_ROOT . 'assets/images/notification.png';
    }

    // Use subject_html raw if present (notifications.php used subject_html)
    $subject = $payload['subject_html'] ?? 'Notification';
    ?>
    <div class="notification-card <?php echo $seen_class; ?>">
        <div class="avatar-wrap">
            <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="avatar" class="notification-avatar" loading="lazy">
            <?php if (!empty($overlay_img)): ?>
                <img src="<?php echo htmlspecialchars($overlay_img); ?>" alt="" class="avatar-overlay" loading="lazy">
            <?php endif; ?>
        </div>
        <div class="notification-content">
            <p><?php echo $subject; ?></p>
            <span class="date"><?php echo htmlspecialchars($time_ago); ?></span>
        </div>
    </div>
    <?php
}
$html = ob_get_clean();

// New cursor values (last item returned)
$last_item = end($rows);
$last_created_at_out = $last_item ? $last_item['created_at'] : null;
$last_notif_id_out = $last_item ? (int)$last_item['id'] : null;

// Return JSON (use last_notif_id to match notifications.php naming)
echo json_encode([
    'html' => $html,
    'count' => count($rows),
    'has_more' => $has_more,
    'last_created_at' => $last_created_at_out,
    'last_notif_id' => $last_notif_id_out
]);
exit;
