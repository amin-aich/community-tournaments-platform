<?php
// backend/get_user_activity.php  (keyset pagination, returns JSON { html, next_cursor })
require_once __DIR__ . '/../_intro.php';
header('Content-Type: application/json; charset=utf-8');

$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$limit     = isset($_GET['limit']) ? max(1,(int)$_GET['limit']) : 10;
$before_at = isset($_GET['before_created_at']) ? trim($_GET['before_created_at']) : '';
$before_id = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;

if ($member_id <= 0) {
    echo json_encode(['html' => '', 'next_cursor' => null]);
    exit;
}

// cheap hard cap
$limit = min($limit, 100);
$fetchLimit = $limit + 1;

if ($before_at !== '' && $before_id > 0) {
    $sql = "
        SELECT id, description, image_url, created_at
        FROM user_activity
        WHERE member_id = ?
          AND (created_at < ? OR (created_at = ? AND id < ?))
        ORDER BY created_at DESC, id DESC
        LIMIT ?
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { echo json_encode(['html' => '', 'next_cursor' => null]); exit; }
    $stmt->bind_param('issii', $member_id, $before_at, $before_at, $before_id, $fetchLimit);
} else {
    $sql = "
        SELECT id, description, image_url, created_at
        FROM user_activity
        WHERE member_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT ?
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { echo json_encode(['html' => '', 'next_cursor' => null]); exit; }
    $stmt->bind_param('ii', $member_id, $fetchLimit);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$res->free();
$stmt->close();

$hasNext = count($rows) > $limit;
if ($hasNext) {
    $rows = array_slice($rows, 0, $limit);
}

$siteRoot = defined('MAIN_ROOT') ? rtrim(MAIN_ROOT, '/') : ($GLOBALS['MAIN_ROOT'] ?? '');
$html = '';
$lastCreatedAt = '';
$lastId = 0;

foreach ($rows as $row) {
    $activityId = (int)$row['id'];
    $desc = $row['description'] ?? '';
    $imageUrl = $row['image_url'] ?? '';
    $createdAt = $row['created_at'] ?? '';

    if ($imageUrl && !preg_match('#^[a-z][a-z0-9+\-.]*://#i', $imageUrl) && strpos($imageUrl, '//') !== 0) {
        $imageUrl = ($siteRoot !== '' ? $siteRoot : '') . '/' . ltrim($imageUrl, '/');
    }
    $imgHtml = $imageUrl
        ? '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . '" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover" />'
        : '<div style="width:56px;height:56px;border-radius:10px;background:#1f2937;display:flex;align-items:center;justify-content:center">🏅</div>';

    $timeOut = '';
    if ($createdAt) {
        $dt = @date_create($createdAt);
        $timeOut = $dt ? htmlspecialchars($dt->format('Y-m-d H:i'), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars($createdAt, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }

    $html .= '<div class="timeline-item" data-activity-id="' . $activityId . '">';
    $html .= $imgHtml;
    $html .= '<div>';
    $html .= '<div class="meta-time">' . $timeOut . '</div>';
    $html .= '<div class="desc">' . $desc . '</div>';
    $html .= '</div></div>';

    $lastCreatedAt = $createdAt;
    $lastId = $activityId;
}

// Build next_cursor as base64(json) or null
$next_cursor = null;
if ($hasNext && $lastCreatedAt !== '' && $lastId > 0) {
    $next_cursor = base64_encode(json_encode(['before_created_at' => $lastCreatedAt, 'before_id' => $lastId]));
}

// Return JSON with the HTML fragment and next_cursor (or null)
echo json_encode([
    'html' => $html,
    'next_cursor' => $next_cursor
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
