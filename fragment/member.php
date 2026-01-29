<?php
// fragment/member.php
// Place at: fragment/member.php
$prevFolder = "../"; // adjust if your fragment folder is elsewhere
include_once($prevFolder . "_intro.php");

// Cache policy: short public cache for anonymous users, private for logged-in.
// Guard header() with headers_sent() in case this fragment is included into a full page.
$anonymous = empty($_SESSION['user_id']);
if (!headers_sent()) {
    if ($anonymous) {
        header('Cache-Control: public, max-age=20'); // tune TTL as needed
    } else {
        header('Cache-Control: private, no-store, must-revalidate');
    }
}

// Validate input
if (!isset($_GET['mID']) || !is_numeric($_GET['mID'])) {
    http_response_code(400);
    exit();
}

$profileId = (int)$_GET['mID'];

// fetch member (removed progress_xp and total_xp)
$stmt = $mysqli->prepare("SELECT disabled, username, bio, profilepic, datejoined, last_online, country, profileviews, member_id, facebook, twitch, youtube FROM {$dbprefix}members WHERE member_id = ?");
$stmt->bind_param("i", $profileId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    exit();
}
$row = $result->fetch_assoc();
$stmt->close();

// ⭐⭐⭐ ADD THIS CODE TO INCREMENT PROFILE VIEWS (ONLY IF NOT OWNER) ⭐⭐⭐
$loggedInMemberId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if (($profileId !== $loggedInMemberId) AND $loggedInMemberId !== 0) {
    $updateStmt = $mysqli->prepare("UPDATE {$dbprefix}members SET profileviews = profileviews + 1 WHERE member_id = ?");
    $updateStmt->bind_param("i", $profileId);
    $updateStmt->execute();
    $updateStmt->close();
}
// ⭐⭐⭐ END OF ADDED CODE ⭐⭐⭐

if ((int)$row['disabled'] !== 0) {
    http_response_code(404);
    exit();
}

$username = $row['username'];
$bio = $row['bio'];
$profilePic = $row['profilepic'] ?: ($MAIN_ROOT . "assets/images/default_avatar.png");
$dateJoined = $row['datejoined'];
$country = $row['country'];
$profileViews = $row['profileviews'];
$memberId = (int)$row['member_id'];
$lastseen = (int)$row['last_online'];

// Page title (used for data-title)
$PAGE_NAME = $username . " - ";

// safe helper for inline output
function s($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// format member since (handles numeric timestamp or SQL datetime string)
$memberSince = '';
if ($dateJoined) {
    if (is_numeric($dateJoined)) {
        $memberSince = date('M j, Y', (int)$dateJoined);
    } else {
        try {
            $dt = new DateTime($dateJoined);
            $memberSince = $dt->format('M j, Y');
        } catch (Exception $e) {
            $memberSince = s($dateJoined);
        }
    }
}

// small country list (same as original)
$countries = [
  '' => 'Not set',
  'US'=>'United States','GB'=>'United Kingdom','CA'=>'Canada','DE'=>'Germany','FR'=>'France',
  'ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','AU'=>'Australia',
  'IN'=>'India','JP'=>'Japan','CN'=>'China','DZ'=>'Algeria','MA'=>'Morocco','EG'=>'Egypt'
];
$currentCountry = $country ?? '';
$flagsBaseUrl = $MAIN_ROOT . 'assets/images/flags/';

$country_image = "";
$country_name = "";
if ($currentCountry && isset($countries[$currentCountry])) {
    $flagPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($flagsBaseUrl, '/') . $currentCountry . '.png';
    if (file_exists($flagPath)) {
        $country_image = "<img src='" . s($flagsBaseUrl . $currentCountry . '.png') . "' alt='" . s($countries[$currentCountry]) . " flag' style='width: 20px; height: 15px; object-fit: cover; border-radius: 2px;'>";
        $country_name = "<span style=''>" . $countries[$currentCountry] . "</span>";
    }
}

// last seen display - show "Active" if online, otherwise time ago
$isActive = ($lastseen && (time() - $lastseen) < 300); // 5 minutes threshold
if ($lastseen == 0) {
    $dispLastSeen = "Never Logged In";
} elseif ($isActive) {
    $dispLastSeen = '<span style="color: #2ecc71; font-weight: 700;">Active</span>';
} else {
    $dispLastSeen = '<span class="lastseen" data-ts="' . (int)$lastseen . '">' . gettimeago($lastseen) . '</span>';
}

// Social URL helper (keeps your original logic, but uses s() for safety)
function social_url($provider, $handle) {
    global $MAIN_ROOT;
    if (!$handle) return null;
    $handle = trim($handle);
    $handle = ltrim($handle, '@');
    $enc = rawurlencode($handle);
    $icon_base = $MAIN_ROOT . "assets/images/socialmedias/";

    if ($provider === 'twitch') {
        $url = "https://www.twitch.tv/" . $enc;
        $img = $icon_base . "twitch.png";
    } elseif ($provider === 'facebook') {
        $url = "https://www.facebook.com/" . $enc;
        $img = $icon_base . "facebook.png";
    } elseif ($provider === 'youtube') {
        if (strpos($handle, 'UC') === 0) {
            $url = "https://www.youtube.com/channel/" . $enc;
        } else {
            $url = "https://www.youtube.com/@" . $enc;
        }
        $img = $icon_base . "youtube.png";
    } else {
        return null;
    }

    $safe_url = s($url);
    $safe_img = s($img);
    $safe_alt = s(ucfirst($provider));

    return "<a href='{$safe_url}' target='_blank' rel='noopener noreferrer nofollow' aria-label='{$safe_alt}'>
                <img src='{$safe_img}' alt='{$safe_alt}' style='margin-right:5px;width:32px;height:32px;'>
            </a>";
}

$fb = $row['facebook']; $tw = $row['twitch']; $yt = $row['youtube'];
$links = [];
if (!empty($fb))  $links[] = social_url('facebook', $fb);
if (!empty($tw))  $links[] = social_url('twitch',   $tw);
if (!empty($yt))  $links[] = social_url('youtube',  $yt);


// ---------- BEGIN: account badge counts ----------
$activity_count = 0;
$events_count = 0;

// activity count
if ($stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM {$dbprefix}user_activity WHERE member_id = ?")) {
    $stmt->bind_param('i', $memberId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        $r = $res->fetch_assoc();
        $activity_count = (int)($r['c'] ?? 0);
        $res->free();
    } else {
        error_log('member.php activity_count execute failed: ' . $stmt->error);
    }
    $stmt->close();
} else {
    error_log('member.php activity_count prepare failed: ' . $mysqli->error);
}

// participated events count (TOURNAMENTS ONLY - speedruns removed)
$events_count = 0;
$events_sql = "
SELECT COUNT(*) AS c
FROM {$dbprefix}tournamentplayers tp
JOIN {$dbprefix}tournamentteams tt ON tp.team_id = tt.tournamentteam_id
JOIN {$dbprefix}tournaments t ON tt.tournament_id = t.tournament_id
WHERE tp.member_id = ?
";
if ($stmt = $mysqli->prepare($events_sql)) {
    $stmt->bind_param('i', $memberId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        $r = $res->fetch_assoc();
        $events_count = (int)($r['c'] ?? 0);
        $res->free();
    } else {
        error_log('member.php events_count execute failed: ' . $stmt->error);
    }
    $stmt->close();
} else {
    error_log('member.php events_count prepare failed: ' . $mysqli->error);
}
// ---------- END: account badge counts ----------


// --- fetch or create member stats (use $dbprefix already defined above) ---
$member_stats = [
  'tournaments_participated' => 0,
  'tournaments_won' => 0,
  'match_wins' => 0,
  'match_losses' => 0
];

$stats_table = 'member_stats';

if ($stmt = $mysqli->prepare("SELECT tournaments_participated, tournaments_won, match_wins, match_losses FROM {$stats_table} WHERE member_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $memberId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($rowStats = $res->fetch_assoc()) {
            $member_stats['tournaments_participated'] = (int)$rowStats['tournaments_participated'];
            $member_stats['tournaments_won'] = (int)$rowStats['tournaments_won'];
            $member_stats['match_wins'] = (int)$rowStats['match_wins'];
            $member_stats['match_losses'] = (int)$rowStats['match_losses'];
        } else {
            // no stats row — create a zero-row so future increments can use ON DUPLICATE KEY
            $res->free();
            if ($ins = $mysqli->prepare("INSERT INTO {$stats_table} (member_id) VALUES (?)")) {
                $ins->bind_param('i', $memberId);
                $ins->execute();
                $ins->close();
            }
        }
        // $res->free();
    } else {
        error_log('member.php stats execute failed: ' . $stmt->error);
    }
    $stmt->close();
} else {
    error_log('member.php stats prepare failed: ' . $mysqli->error);
}


// Begin fragment output
?>

<style>
.profile-page { display:flex; gap:20px; padding:20px; color:#e8eef6; font-family:Inter,Arial,sans-serif; }
.tabs { display:flex; gap:8px; margin-bottom:40px; border-bottom:1px solid #222a32; }
.tabs button { padding:8px 12px; border:none; background:none; color:#9aa4b2; font-size:16px; font-weight:bold; cursor:pointer; }
.tabs button.active { color:#1fd2f1;border-bottom:2px solid #1fd2f1; }
.participated-events { display:flex; flex-direction:column; gap:12px; }
.timeline { border-left:3px solid rgba(255,255,255,0.00); }
.timeline-item { margin-bottom:24px; padding-left:12px; display:flex; gap:12px; align-items:center; }
.timeline-item img { width:56px; height:56px; border-radius:10px; object-fit:cover; }
.timeline-item .meta-time { color:#9aa4b2; font-size:13px; margin-bottom:6px; }
.timeline-item .desc { margin-top:4px; line-height:1.3; font-size:15px; color:#e6eef6; }
.btn.ghost { padding:8px 12px; border-radius:8px; background:transparent; border:1px solid rgba(255,255,255,0.4); color:#9aa4b2; cursor:pointer; }
.tab-badge { display: inline-block; min-width: 22px; padding: 2px 1px; border-radius: 999px; background: rgba(31, 210, 241, 0.12); font-weight:800; font-size: 13px; line-height: 1; text-align: center; margin-left: 5px; }
.tab-badge.zero { opacity: 0.35; }
nav.tabs { align-items:center; }
nav.tabs button { display:inline-flex; align-items:center; gap:1px; }

/* Left column polished styles (scoped) */
.profile-card {
  background: none;
  border-radius: 14px;
  padding: 0px;
  width: 100%;
  margin: 0px 0px 30px 0px;
  color: #e8eef6;
  padding: 0px 0px;
}
.profile-avatar {
  position: relative;
  width: 140px;
  height: 140px;
  margin: 0 auto;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 6px 18px rgba(0,0,0,0.35);
  background: #0f1720;
}
.profile-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
.profile-name-row {
  display:flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  margin-top:30px;
  text-align:center;
}
.profile-username {
  font-size: clamp(18px, 3.6vw, 22px);
  font-weight: 700;
  color: #1fd2f1;
  word-break: break-word;
}
.profile-sub {
  color: #9aa4b2;
  font-size: 13px;
  margin-top:15px;
  text-align:center;
}
.presence-box {
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  margin-top:0px;
  color:#9aa4b2;
  font-size:13px;
}
.presence-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
.presence-dot.online { background: #2ecc71; box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7); border-radius: 50%; animation: pulse-green 2s infinite;}
.presence-dot.offline { background:#6b7280; }

@keyframes pulse-green {
	0% {
		transform: scale(0.95);
		box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7);
	}
	70% {
		transform: scale(1);
		box-shadow: 0 0 0 10px rgba(22, 163, 74, 0);
	}
	100% {
		transform: scale(0.95);
		box-shadow: 0 0 0 0 rgba(22, 163, 74, 0);
	}
}

.level-card {
  margin:30px 18px;
  background: #181b22;
  border-radius: 10px;
  padding: 12px;
  text-align:left;
  border:none;
}
.level-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.xp-stats { font-size:13px; color:#9aa4b2; display:flex; gap:8px; align-items:center; }
.progress-track { height:10px; background:hsl(228deg 5% 34% / 63%); border-radius:999px; overflow:hidden; margin-top:8px; }
.progress-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#00d6ff,#00a3ff); }

.summary-grid {
  display:flex;
  gap:12px;
  justify-content:space-around;
  align-items:center;
}
.summary-item { text-align:center; flex:1; }
.summary-item .num { font-weight:700; color:#e6eef6; display:block; font-size:16px; }
.summary-item .label { font-size:12px; color:#9aa4b2; }

.social-row { display:flex; gap:8px; justify-content:center; margin-top:30px; flex-wrap:wrap; }
.social-row a img { width:34px; height:34px; border-radius:6px; box-shadow:0 6px 12px rgba(0,0,0,0.4); }
.action-links { display:flex; gap:8px; justify-content:center; margin-top:30px; flex-wrap:wrap; }
.btn-link { background:transparent; border:1px solid rgba(255,255,255,0.06); padding:8px 10px; border-radius:8px; color:#9aa4b2; text-decoration:none; font-size:13px; }
</style>

<div class='userProfileSecs' data-title="<?= s(trim($PAGE_NAME) . ' ' . $DOMAIN_NAME) ?>">

    <!-- ===== polished left side START ===== -->
	<div class="profileLeftSide" aria-labelledby="profile-name">

	  <div class="profile-card" role="region" aria-label="<?= s($username) ?> profile summary">
		<!-- avatar -->
		<div class="profile-avatar" aria-hidden="false">
		  <img src="<?= s($profilePic) ?>" alt="<?= s($username) ?> avatar" loading="lazy">
		  <!-- level removed: simplified profile (no XP/levels) -->
		</div>

		<!-- name + flag -->
		<div class="profile-name-row" id="profile-name">
		  <div>
			<div class="profile-username"><?= s($username) ?></div>
			
			<?php if (!empty($bio)): ?>
			<div class="bio-section" style="text-align:center; margin:0px 0px; padding:7px; border-radius:25px;">
				<p class="bio-content" style="margin:5px 0px; font-size:14px; line-height:1.5; color:#9aa4b2; max-width:600px;">
					<?php echo nl2br(htmlspecialchars($bio)); ?>
				</p>
			</div>
			<?php endif; ?>
			
			<!-- presence: live status + last-seen 
			<div id="member-status" data-user-id="<?php // (int)$memberId ?>" class="presence-box" aria-live="polite" aria-atomic="true" style="margin-bottom:6px;">
			  <span id="presence-dot" class="presence-dot <?php // $isActive ? 'online' : 'offline' ?>" aria-hidden="true"></span>
			  <span id="presence-text" class="presence-text"><?php // $dispLastSeen ?></span>
			</div>
			-->
			
		  </div>
		</div>

		<div class="presence-box" aria-live="polite" aria-atomic="true" style="margin-bottom:6px;">
		  <?php if ($country_name): ?>
			<div class="profile-sub" style="display: flex; text-align: center; align-items: center; justify-content: center; gap: 10px;">
				<?php echo $country_image; ?> 
				<?php echo $country_name; ?>
			</div>
		  <?php endif; ?>
		</div>

		<!-- simplified stats panel -->
		<div class="level-card" aria-hidden="false">

		  <div class="summary-grid" style="">
			<div class="summary-item">
			  <span class="label">All tournaments</span>
			  <span class="num"><?= s(number_format($member_stats['tournaments_participated'])) ?></span>
			</div>

			<div class="summary-item">
			  <span class="label">Tournaments won</span>
			  <span class="num" style="color:gold;"><?= s(number_format($member_stats['tournaments_won'])) ?></span>
			</div>
		  </div>

		  <div class="summary-grid" style="margin-top:16px;">
			<div class="summary-item">
			  <span class="label">Match wins</span>
			  <span class="num" style="color:#0bf115;"><?= s(number_format($member_stats['match_wins'])) ?></span>
			</div>

			<div class="summary-item">
			  <span class="label">Match losses</span>
			  <span class="num" style="color:#ff6b6b;"><?= s(number_format($member_stats['match_losses'])) ?></span>
			</div>
		  </div>
		</div>

		<!-- social links -->
		<?php if (count($links) > 0): ?>
		  <div class="social-row" aria-label="social links">
			<?php foreach ($links as $html) echo $html; ?>
		  </div>
		<?php endif; ?>

	  </div>
	</div>


    <div class='profileRightSide'>
		
		<nav class="tabs" role="tablist" aria-label="Profile tabs">
		  <button data-tab="activity" class="active" role="tab" aria-selected="true">
			Activity
			<span class="tab-badge <?= $activity_count === 0 ? 'zero' : '' ?>" aria-hidden="false"><?= s(number_format($activity_count)) ?></span>
		  </button>

		  <button data-tab="events" role="tab" aria-selected="false">
			Tournaments
			<span class="tab-badge <?= $events_count === 0 ? 'zero' : '' ?>" aria-hidden="false"><?= s(number_format($events_count)) ?></span>
		  </button>
		</nav>


        <section id="tab-events" class="tab-content" style="display:none;">
            <div id="participated-events-container" class="participated-events"></div>
            <div style="text-align:center; margin-top:12px;">
                <button id="load-more-events" class="btn ghost">Load more tournaments</button>
            </div>
        </section>

        <section id="tab-activity" class="tab-content" style="display:block;">
            <div id="activity-timeline" class="timeline">Loading activity...</div>
            <div style="text-align:center; margin-top:12px;">
                <button id="load-more-activity" class="btn ghost">Load more</button>
            </div>
        </section>
    </div>

</div>

<script>
(function () {
    // Server-inserted values (fragment includes this file after PHP variables exist)
    const PROFILE_MEMBER_ID = <?= (int)$memberId ?>;
    const SITE_ROOT = <?= json_encode($MAIN_ROOT) ?>.replace(/\/$/, '') + '/';

    // Use absolute backend endpoints so they work from /fragment/... or root pages
    const EVENTS_HTML_URL  = SITE_ROOT + 'backend/get_participated_events.php';
    const ACTIVITY_HTML_URL= SITE_ROOT + 'backend/get_user_activity.php';

    const VIEWER_TZ = (Intl && Intl.DateTimeFormat) ? (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC') : 'UTC';

    // wrapper to run initialization immediately if DOM already ready, otherwise wait
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            // DOM already loaded: run async so we don't block
            setTimeout(fn, 0);
        }
    }

    onReady(function () {
        // defensive DOM lookups
        const eventsContainer = document.getElementById('participated-events-container');
        const loadMoreEventsBtn = document.getElementById('load-more-events');
        const activityContainer = document.getElementById('activity-timeline');
        const loadMoreActivityBtn = document.getElementById('load-more-activity');
        const tabsButtons = document.querySelectorAll('.tabs button');

        // ---------- Presence helpers (safe for AJAX-loaded fragment) ----------
        (function () {
            // human-readable relative time from unix seconds
            function relTimeFromTs(tsSec) {
                if (!tsSec || Number(tsSec) === 0) return 'Never Logged In';
                const diff = Math.floor(Date.now() / 1000) - Number(tsSec);
                if (diff < 10) return 'just now';
                if (diff < 60) return diff + ' second' + (diff > 1 ? 's' : '') + ' ago';
                if (diff < 3600) return Math.floor(diff / 60) + ' minute' + (Math.floor(diff / 60) > 1 ? 's' : '') + ' ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hour' + (Math.floor(diff / 3600) > 1 ? 's' : '') + ' ago';
                if (diff < 2592000) return Math.floor(diff / 86400) + ' day' + (Math.floor(diff / 86400) > 1 ? 's' : '') + ' ago';
                return new Date(Number(tsSec) * 1000).toLocaleString();
            }

            // Define/update functions only if not defined (avoids stomping other implementations)
            if (typeof window.updateLastseenDisplay !== 'function') {
                window.updateLastseenDisplay = function (tsSec) {
                    try {
                        const textEl = document.getElementById('presence-text');
                        const dot = document.getElementById('presence-dot');
                        if (!textEl) return;
                        // store timestamp for periodic refresh
                        textEl.dataset.lastSeenTs = tsSec ? String(tsSec) : '0';

                        // If element shows active (dot has 'online'), keep Active label
                        if (dot && dot.classList.contains('online')) {
                            textEl.innerHTML = '<span style="color:#2ecc71; font-weight:700;">Active</span>';
                            textEl.title = '';
                            return;
                        }

                        // Render relative/time tooltip
                        const rel = relTimeFromTs(tsSec ? Number(tsSec) : 0);
                        textEl.textContent = rel;
                        if (tsSec && Number(tsSec) > 0) {
                            textEl.title = new Date(Number(tsSec) * 1000).toLocaleString();
                        } else {
                            textEl.title = '';
                        }
                    } catch (e) {
                        console.warn('updateLastseenDisplay error', e);
                    }
                };
            }

            if (typeof window.setProfileOnline !== 'function') {
                window.setProfileOnline = function (isOnline) {
                    try {
                        const dot = document.getElementById('presence-dot');
                        const textEl = document.getElementById('presence-text');
                        if (!dot || !textEl) return;

                        if (Boolean(isOnline)) {
                            dot.classList.remove('offline');
                            dot.classList.add('online');
                            textEl.innerHTML = '<span style="color:#2ecc71; font-weight:700;">Active</span>';
                            textEl.title = '';
                        } else {
                            dot.classList.remove('online');
                            dot.classList.add('offline');
                            const ts = textEl.dataset.lastSeenTs ? Number(textEl.dataset.lastSeenTs) : 0;
                            window.updateLastseenDisplay(ts);
                        }
                    } catch (e) {
                        console.warn('setProfileOnline error', e);
                    }
                };
            }

            // Start a single periodic updater per presence-text element (guarded)
            try {
                const textEl = document.getElementById('presence-text');
                if (textEl && !textEl.dataset._lastseenUpdaterAttached) {
                    textEl.dataset._lastseenUpdaterAttached = '1';
                    // if a .lastseen element exists on this fragment, copy its ts into presence-text
                    const anyLastseen = document.querySelector('.lastseen');
                    if (anyLastseen && anyLastseen.dataset && anyLastseen.dataset.ts) {
                        textEl.dataset.lastSeenTs = anyLastseen.dataset.ts;
                    }
                    // run periodic refresh (every 60s)
                    setInterval(function () {
                        const dot = document.getElementById('presence-dot');
                        if (dot && dot.classList.contains('online')) return; // don't update while online
                        const ts = textEl.dataset.lastSeenTs ? Number(textEl.dataset.lastSeenTs) : 0;
                        window.updateLastseenDisplay(ts);
                    }, 60000);
                }
            } catch (e) {
                // non-fatal
            }
        })();
        // -------------------------------------------------------------------

        // ---------- Participated events loading (cursor / batch) ----------
        let eventsLoadedOnce = false;
        const EVENTS_BATCH = 6;
        let eventsCursor = null;

        async function loadParticipatedEvents(initial = false) {
            if (!eventsContainer || !loadMoreEventsBtn) return;
            try {
                if (initial) {
                    eventsCursor = null;
                    eventsContainer.innerHTML = '';
                }
                loadMoreEventsBtn.disabled = true;
                loadMoreEventsBtn.textContent = 'Loading...';

                let url = `${EVENTS_HTML_URL}?member_id=${encodeURIComponent(PROFILE_MEMBER_ID)}&limit=${EVENTS_BATCH}&viewer_tz=${encodeURIComponent(VIEWER_TZ)}`;
                if (eventsCursor && typeof eventsCursor === 'object' && eventsCursor.before_start !== undefined && eventsCursor.before_id !== undefined) {
                    url += `&cursor=${encodeURIComponent(btoa(JSON.stringify(eventsCursor)))}`;
                }

                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                const html = data.html || '';
                const nextCursorB64 = data.next_cursor || null;

                if (!html || html.trim() === '') {
                    if (!eventsCursor) eventsContainer.innerHTML = '<div style="color:#9aa4b2">No tournaments found.</div>';
                    loadMoreEventsBtn.style.display = 'none';
                    loadMoreEventsBtn.disabled = false;
                    loadMoreEventsBtn.textContent = 'Load more tournaments';
                    eventsLoadedOnce = true;
                    return;
                }

                const temp = document.createElement('div');
                temp.innerHTML = html;
                while (temp.firstChild) eventsContainer.appendChild(temp.firstChild);

                if (nextCursorB64) {
                    try {
                        const parsed = JSON.parse(atob(nextCursorB64));
                        if (parsed && parsed.before_start !== undefined && parsed.before_id !== undefined) {
                            eventsCursor = {
                                before_start: parsed.before_start,
                                before_id: parsed.before_id,
                                before_type: parsed.before_type || 2
                            };
                            loadMoreEventsBtn.style.display = 'inline-block';
                            loadMoreEventsBtn.disabled = false;
                            loadMoreEventsBtn.textContent = 'Load more tournaments';
                        } else {
                            eventsCursor = null;
                            loadMoreEventsBtn.style.display = 'none';
                        }
                    } catch (err) {
                        console.warn('Failed to parse events next_cursor', err);
                        eventsCursor = null;
                        loadMoreEventsBtn.style.display = 'none';
                    }
                } else {
                    eventsCursor = null;
                    loadMoreEventsBtn.style.display = 'none';
                }

                eventsLoadedOnce = true;

            } catch (err) {
                console.error('loadParticipatedEvents failed', err);
                loadMoreEventsBtn.disabled = false;
                loadMoreEventsBtn.textContent = 'Load more tournaments';
            }
        }

        if (loadMoreEventsBtn) {
            loadMoreEventsBtn.addEventListener('click', () => loadParticipatedEvents(false));
        }
        // -------------------------------------------------------------------

        // ----------------- Activity loading (cursor / batch) -----------------
        let activityCursor = null;
        const ACTIVITY_BATCH = 10;

        async function loadActivity(initial = false) {
            if (!activityContainer || !loadMoreActivityBtn) return;
            try {
                if (initial) {
                    activityCursor = null;
                    activityContainer.innerHTML = '';
                }
                loadMoreActivityBtn.disabled = true;
                loadMoreActivityBtn.textContent = 'Loading...';

                let url = `${ACTIVITY_HTML_URL}?member_id=${encodeURIComponent(PROFILE_MEMBER_ID)}&limit=${ACTIVITY_BATCH}`;
                if (activityCursor && activityCursor.before_created_at && activityCursor.before_id) {
                    url += `&before_created_at=${encodeURIComponent(activityCursor.before_created_at)}&before_id=${encodeURIComponent(activityCursor.before_id)}`;
                }

                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                const html = data.html || '';
                const nextCursorB64 = data.next_cursor || null;

                if (!html || html.trim() === '') {
                    if (!activityCursor) activityContainer.innerHTML = '<div style="color:#9aa4b2">No activity yet.</div>';
                    loadMoreActivityBtn.style.display = 'none';
                    loadMoreActivityBtn.disabled = false;
                    loadMoreActivityBtn.textContent = 'Load more';
                    return;
                }

                const temp = document.createElement('div');
                temp.innerHTML = html;
                while (temp.firstChild) activityContainer.appendChild(temp.firstChild);

                if (nextCursorB64) {
                    try {
                        const parsed = JSON.parse(atob(nextCursorB64));
                        if (parsed && parsed.before_created_at && parsed.before_id) {
                            activityCursor = {
                                before_created_at: parsed.before_created_at,
                                before_id: parsed.before_id
                            };
                            loadMoreActivityBtn.style.display = 'inline-block';
                            loadMoreActivityBtn.disabled = false;
                            loadMoreActivityBtn.textContent = 'Load more';
                        } else {
                            activityCursor = null;
                            loadMoreActivityBtn.style.display = 'none';
                        }
                    } catch (err) {
                        console.warn('Failed to parse next_cursor', err);
                        activityCursor = null;
                        loadMoreActivityBtn.style.display = 'none';
                    }
                } else {
                    activityCursor = null;
                    loadMoreActivityBtn.style.display = 'none';
                }

            } catch (err) {
                console.error('loadActivity failed', err);
                if (loadMoreActivityBtn) {
                    loadMoreActivityBtn.disabled = false;
                    loadMoreActivityBtn.textContent = 'Load more';
                }
            }
        }

        if (loadMoreActivityBtn) {
            loadMoreActivityBtn.addEventListener('click', () => loadActivity(false));
        }
        // -------------------------------------------------------------------

        // Tab switching
        if (tabsButtons && tabsButtons.length) {
            tabsButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    tabsButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const tab = btn.dataset.tab;
                    const tabActivity = document.getElementById('tab-activity');
                    const tabEvents   = document.getElementById('tab-events');
                    if (tabActivity) tabActivity.style.display = (tab === 'activity') ? 'block' : 'none';
                    if (tabEvents)   tabEvents.style.display = (tab === 'events') ? 'block' : 'none';
                    if (tab === 'events' && !eventsLoadedOnce) loadParticipatedEvents(true);
                });
            });
        }

        // initial loads (medals + initial activity page)
        loadActivity(true);

        // ---- lastseen conversion: run now (works same as original) ----
        document.querySelectorAll('.lastseen').forEach(function (el) {
            const ts = Number(el.dataset.ts) || 0;
            if (!ts) { el.textContent = 'Never Logged In'; return; }
            el.title = new Date(ts * 1000).toLocaleString();
            const text = (el.textContent || '').trim();
            const looksLikeAbsolute = /\d{4}|:/.test(text);

            function relText() {
                const diff = Math.floor(Date.now() / 1000) - ts;
                if (diff < 10) return 'just now';
                if (diff < 60) return diff + ' second' + (diff > 1 ? 's' : '') + ' ago';
                if (diff < 3600) return Math.floor(diff / 60) + ' minute' + (Math.floor(diff / 60) > 1 ? 's' : '') + ' ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hour' + (Math.floor(diff / 3600) > 1 ? 's' : '') + ' ago';
                if (diff < 2592000) return Math.floor(diff / 86400) + ' day' + (Math.floor(diff / 86400) > 1 ? 's' : '') + ' ago';
                return new Date(ts * 1000).toLocaleString();
            }

            if (looksLikeAbsolute) {
                el.textContent = new Date(ts * 1000).toLocaleString();
            } else {
                el.textContent = relText();
                setInterval(function () {
                    el.textContent = relText();
                }, 60000);
            }
        });
        // ---- end lastseen ----
    });
})();
</script>

<?php
// DO NOT include assets/_footer.php in fragment
?>