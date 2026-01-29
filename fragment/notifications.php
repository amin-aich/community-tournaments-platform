<?php
// fragment/notifications.php
$prevFolder = "../"; // adjust if fragment folder is elsewhere
include_once($prevFolder . "_intro.php");

// Cache policy: short public cache for anonymous users, private for logged-in.
// Guard header() to avoid "headers already sent" when included server-side.
$anonymous = empty($_SESSION['user_id']);
if (!headers_sent()) {
    if ($anonymous) {
        header('Cache-Control: public, max-age=20');
    } else {
        header('Cache-Control: private, no-store, must-revalidate');
    }
}

define("NOTIFS_PAGE", true);

// Ensure session started in _intro.php (assumed)
if (!isset($_SESSION['user_id'])) {
	?>
	
		<div class="notification-container">
			<div class="clear-all-div">
				<h2 style="font-size:1.4rem; margin:0 0 6px 0;">Notifications</h2>
			</div>
			<div >
				<p align="center">You are not Logged in! please <a data-async href="<?php echo MAIN_ROOT.'auth.php'; ?>" style="position: relative;" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment('<?php echo htmlspecialchars(MAIN_ROOT . 'fragment/auth.php'); ?>', true); else location.href='<?php echo htmlspecialchars(MAIN_ROOT . 'auth.php') ; ?>';">Login</a></p>
			</div>
		</div>
	
	<?php
	exit();
}

$user_id = (int) $_SESSION['user_id'];

// Fetch latest notifications (first page)
$batchSize = 5;
$notifications = [];
$sql = "SELECT id, target_user_id, type, payload, seen, created_at
        FROM {$dbprefix}notifications
        WHERE target_user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT ?";
if ($stmt = $mysqli->prepare($sql)) {
	$stmt->bind_param("ii", $user_id, $batchSize);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) {
		$notifications[] = $row;
	}
	$res->free();
	$stmt->close();
} else {
	// Query prepare failed
	echo "<main id='app-root' class='container' role='main' tabindex='-1'><div><p lign='center'>Unable to load notifications.</p></div></main>";
	include($prevFolder . "assets/_footer.php");
	exit();
}

// Prepare show/have-more state and initial cursor values
$notifications_count = count($notifications);
$show_load_more = ($notifications_count === $batchSize);

// last cursor values (null if no rows)
$last_created_at = $notifications_count ? $notifications[$notifications_count - 1]['created_at'] : null;
$last_notif_id   = $notifications_count ? (int)$notifications[$notifications_count - 1]['id'] : null;

$js_last_created_at = json_encode($last_created_at);
$js_last_notif_id   = json_encode($last_notif_id);

?>

	
	<div style="display:flex; justify-content:center; align-items:center;"></div>

	<?php if ($notifications_count > 0): ?>
		<div class="clear-all-div">
			<h2 style="font-size:1.4rem; margin:0 0 6px 0;">Notifications</h2>
			<button id="clear-all-btn" style="border:none;background:transparent;color:#9ca3af;border-radius:5px;cursor:pointer;font-size:15px;font-weight:bold;width:fit-content;">
				Clear All
			</button>
		</div>
	<?php endif; ?>

	<div id="notifications-container" class="notifications-list">
		<?php
		if ($notifications_count > 0) {
			foreach ($notifications as $notification) {
				$payload = json_decode($notification['payload'], true);
				if (!is_array($payload)) $payload = [];

				$seen = (int)$notification['seen'] === 1;
				$seen_class = $seen ? '' : 'unseen';

				$created_at = strtotime($notification['created_at']);
				$time_ago = function_exists('gettimeago') ? gettimeago($created_at) : date('Y-m-d H:i', $created_at);

				$avatar_url = $MAIN_ROOT . 'assets/images/notification.png';
				$overlay_img = $MAIN_ROOT . 'assets/images/notification.png';

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
					
				} elseif ($action === "join") {
					if (!empty($payload['avatar_url'])) {
						$avatar_url = (strpos($payload['avatar_url'], 'http://') === 0 || strpos($payload['avatar_url'], 'https://') === 0)
							? $payload['avatar_url']
							: $MAIN_ROOT . ltrim($payload['avatar_url'], '/');
						
					}
					
					$overlay_img = $MAIN_ROOT . "assets/images/tournament.png";
					
				}
				
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
		} else {
			echo "<div class='shadedBox' id='shadedBox'><p class='main' align='center'><i>No new notifications.</i></p></div>";
		}
		?>
	</div>

	<?php if ($show_load_more): ?>
		<div class='load-more-btn-wrapper'>
			<img src="<?php echo htmlspecialchars($MAIN_ROOT); ?>assets/images/arrow_down.png" style="width:16px;height:16px;vertical-align:middle;">
			<button id='load-more-btn' class='load-more-btn'>Load More</button>
		</div>
	<?php endif; ?>

<script>
    // CSRF token for JS POSTs (if you have one creation logic)
    window.SITE = {
        csrf_token: "<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token']) : ''; ?>",
        base: "<?php echo htmlspecialchars($MAIN_ROOT); ?>"
    };
</script>


<?php
// Embed server-side values safely in JS
$js_user_id = json_encode($user_id);
$js_csrf = json_encode($_SESSION['csrf_token'] ?? ($_SESSION['csrftokken'] ?? ''));
$js_main_root = json_encode($MAIN_ROOT);
$js_batch_size = json_encode($batchSize);

// include cursor JS values already prepared above
$js_last_created_at = $js_last_created_at;
$js_last_notif_id = $js_last_notif_id;
?>

<?php
include($prevFolder . "assets/js/notification_js.php");


// DO NOT include assets/_footer.php in the fragment
?>