<?php
// fragment/welcome.php
// Place this file at: fragment/welcome.php
$prevFolder = "../"; // adjust if your fragment folder is elsewhere
include_once($prevFolder . "_intro.php");

// if (isset($_SESSION['user_id'])) {
	// header("Location: ".$MAIN_ROOT."community.php");
	// exit();
// }

include_once($prevFolder . "classes/game.php");

$gameObj = new Game($mysqli);

$LOGIN_FAIL = true;
if (defined("LOGGED_IN")) $LOGIN_FAIL = false;

// Cache policy: short public cache for anonymous users, private for logged-in
$anonymous = empty($_SESSION['user_id']);
if (!headers_sent()) {
    if ($anonymous) {
        header('Cache-Control: public, max-age=30'); // tune TTL as needed
    } else {
        header('Cache-Control: private, no-store, must-revalidate');
    }
} // else: headers already sent (fragment included in a full page), so skip calling header()

// Page variables (same logic as original)
$PAGE_NAME = "Competitions - ";

// current filter (game)
$filterGameId = isset($_GET['game_id']) && is_numeric($_GET['game_id']) ? intval($_GET['game_id']) : 0;
// list of games for the select box (shared)
$arrGames = $gameObj->getGameList();
$gameMap = [];
if (!empty($arrGames)) {
    foreach ($arrGames as $gid) {
        $gameObj->select($gid);
        $info = $gameObj->get_info();
        $gameMap[intval($info['gamesplayed_id'])] = $info;
    }
}
/* -------------------------
   Fetch tournaments
   ------------------------- */
$tournaments = [];
$tTable = $dbprefix . 'tournaments';
$sqlT = "
    SELECT t.tournament_id, t.member_id, t.gamesplayed_id, t.name AS tour_name, t.status, t.eventtype, t.startdate,
           t.platform, t.t_prize, t.t_prize_currency, t.winner_member_id, t.playersperteam, t.participants_count,
           t.maxteams AS maxteams, t.awards_assigned,
           g.imageurl, g.name AS game_name
    FROM {$tTable} AS t
    JOIN {$dbprefix}gamesplayed AS g ON t.gamesplayed_id = g.gamesplayed_id
";
if ($filterGameId > 0) {
    $sqlT .= " WHERE t.gamesplayed_id = ? ";
}
$sqlT .= " ORDER BY t.startdate DESC";
if ($stmt = $mysqli->prepare($sqlT)) {
    if ($filterGameId > 0) {
        $stmt->bind_param("i", $filterGameId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $tournaments[] = $row;
    $stmt->close();
} else {
    echo "<div class='empty'>Database error (tournaments).</div>";
}
/* -------------------------
   Normalize tournaments - SIMPLIFIED
   ------------------------- */
$normalized = [];
foreach ($tournaments as $t) {
    $tid = intval($t['tournament_id']);
    $created_by = intval($t['member_id']);
    $start_ts = intval($t['startdate']);
    $prizeVal = (isset($t['t_prize']) && $t['t_prize'] !== null && $t['t_prize'] !== '') ? rtrim(rtrim(number_format((float)$t['t_prize'], 8, '.', ''), '0'), '.') : '';
    $t_prize_c = $t['t_prize_currency'];
    $img = $t['imageurl'] ?? '';
    $winner_id = intval($t['winner_member_id'] ?? 0);
    $hasWinner = $winner_id > 0;
    $awardsAssigned = intval($t['awards_assigned'] ?? 0) === 1;

    // SIMPLIFIED STATUS LOGIC - Only 3 statuses
    $now = time();
    
    if ($awardsAssigned || $hasWinner) {
        // Status: Finished
        $serverStatus = "finished";
        $customCountColor = "#6b7280"; // Gray
    } elseif ($start_ts > 0 && $now < $start_ts) {
        // Status: Upcoming (Registration ends in start date)
        $serverStatus = "upcoming";
        $customCountColor = "#10b981"; // Green
    } else {
        // Status: Ongoing
        $serverStatus = "ongoing";
        $customCountColor = "#f59e0b"; // Orange
    }

    // ISO for JS
    $startIso = ($start_ts > 0) ? (new DateTime('@'.$start_ts))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM) : '';
    
    $normalized[] = [
        'id' => $tid,
        'title' => $t['tour_name'] ?? ("Tournament #{$tid}"),
        'game_name' => $t['game_name'] ?? ($gameMap[$t['gamesplayed_id']]['name'] ?? 'Unknown'),
		'event_type'=> intval($t['eventtype'] ?? 0),
        'creator' => $created_by,
        'imageurl' => $img,
        'prize' => $prizeVal,
        'prize_currency' => $t_prize_c,
        'start_ts' => $start_ts,
        'start_iso' => $startIso,
        'server_status' => $serverStatus,
        'count_color' => $customCountColor,
        'awards_assigned' => $awardsAssigned,
        'url' => MAIN_ROOT . "tournaments/tournament.php?tID={$tid}",
        'participants' => intval($t['participants_count'] ?? 0),
		'maxteams' => intval($t['maxteams'] ?? 0),
    ];
}
/* sort list by start_ts desc */
usort($normalized, function($a, $b){
    $ta = intval($a['start_ts']);
    $tb = intval($b['start_ts']);
    if ($ta === $tb) return intval($b['id']) <=> intval($a['id']);
    return $tb <=> $ta;
});
?>

<style>
/* ---------- Shared / unified styles ---------- */
.events { padding:0px; }
/* Top row (title + filter) */
.top-row { display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:25px; flex-wrap:wrap; }
.title { color:#fff; font-size:1.4rem; margin:0; }
/* custom select (game) */
.custom-select { position:relative; min-width:220px; }
.custom-select .select-btn {
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 12px; border-radius:8px; background:#071428; color:#e6eef8; cursor:pointer;
    border:1px solid rgba(255,255,255,0.04);
}
.custom-select .options {
    position:absolute; top:calc(100% + 8px); left:0; right:0; background:#021124; border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,0.6); z-index:50; display:none; max-height:220px; overflow:auto;
}
.custom-select.open .options { display:block; }
.custom-select .options .opt { padding:10px 12px; cursor:pointer; border-bottom:1px solid rgba(255,255,255,0.02); color:#cfe6ff; }
.custom-select .options .opt:last-child { border-bottom:0; }
.custom-select .options .opt:hover { background:rgba(255,255,255,0.02); }
.custom-select .arrow { margin-left:12px; transform:rotate(0deg); transition:transform .15s ease; }
.custom-select.open .arrow { transform:rotate(180deg); }
.clear-link { color:#9fb0c8; text-decoration:underline; font-size:0.95rem; }
/* grid */
.events-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(320px,1fr)); gap:30px; margin-top:12px; }
/* unified card */
.event-card {
  position:relative;
  border: 1px solid rgba(60,70,80,0.5);
  border-radius: 12px;
  overflow: hidden;
  transition: transform 0.14s ease, box-shadow 0.14s ease;
  text-decoration:none; color:inherit; display:block;
}
.event-card:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(0,0,0,0.6); }
/* cover */
.cover { width:100%; height:160px; object-fit:cover; }
.meta-compact .prize-inline {
  padding:6px 8px;
  font-size:0.88rem;
  line-height:1;
  display:flex;
  flex-direction: column;
  align-items:center;
  justify-content:center;
  min-width:62px;
  text-align:center;
  color: #1fd2f1;
}
/* card body */
.card-body { padding:14px; }
.card-title { display:flex; justify-content:space-between; gap:10px; height: 85px; margin-bottom: 15px; }
.card-title .left { display:flex; flex-direction:column; gap:4px; }
.card-title .left .title-main { color:#dbefff; font-weight:800; font-size:1.05rem; margin:0; }
.card-title .left .subtitle { color:#1fd2f1; font-size:0.92rem; margin-top:2px; }
.card-title .left .eventtype { color:#e91e63; font-size:0.92rem; margin-top:0px; }

/* countdown / status area */
.count-area { margin-top:15px; display:flex; align-items:center; flex-direction:column; gap:8px; }
.status-text { 
    font-size: 0.9rem; 
    text-align: center; 
    width: 100%;
    margin-bottom: 5px;
    font-weight: 600;
}
.countbox {
  background: rgba(255,255,255,0.03);
  border-radius:10px;
  padding:12px 10px;
  min-width:130px;
  text-align:center;
  display:flex;
  flex-direction:row;
  align-items:center;
  justify-content:center;
  gap:10px;
  width:90%;
  box-shadow: inset 0 -6px 12px rgba(0,0,0,0.25);
}
.count-num { font-weight:900; font-size:1.15rem; letter-spacing:0.5px; }
/* Status-specific styles */
.countbox.upcoming {
  box-shadow: 0 10px 30px rgba(16,185,129,0.22), inset 0 -6px 12px rgba(0,0,0,0.25);
  transform: translateY(-2px);
  border: 1px solid rgba(16,185,129,0.25);
  background: linear-gradient(180deg, rgba(10,30,18,0.55), rgba(4,8,6,0.35));
}
.countbox.ongoing {
  box-shadow: 0 10px 30px rgba(245,158,11,0.22), inset 0 -6px 12px rgba(0,0,0,0.25);
  transform: translateY(-2px);
  border: 1px solid rgba(245,158,11,0.25);
  background: linear-gradient(180deg, rgba(30,20,10,0.55), rgba(8,6,4,0.35));
}

/* Flickering orange dot */
.ongoing-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    margin-right: 10px;
    border-radius: 50%;
    background-color: #f59e0b;
    animation: pulseFade 1.5s ease-in-out infinite;
    vertical-align: middle;
}

@keyframes pulseFade {
    0%   { opacity: 0.2; }
    50%  { opacity: 1; }
    100% { opacity: 0.2; }
}

.countbox.finished {
  background: rgba(255,255,255,0.02);
  opacity: 0.7;
}



/* small meta on the right */
.meta-compact { color:#bfc7cc; font-size:0.92rem; font-weight:700; display:flex; align-items:center; gap:8px; }
/* mobile */
@media (max-width:800px) {
  .events-grid { grid-template-columns: 1fr; }
  .cover { height:140px; }
}
</style>
<div class="events">
<div class="top-row">
<h1 class="title">Competitions</h1>
<?php if ($LOGIN_FAIL === false): ?>
<?php if ($_SESSION['user_id'] === 1): ?>
<a class="btn" href="<?php echo htmlspecialchars(MAIN_ROOT . 'tournaments/create.php'); ?>">
Create
</a>
<?php endif; ?>
<?php endif; ?>
<form method="get" class="form-select" id="game-filter-form" style="margin:0; display: flex; align-items:center; gap:10px;">
<div class="custom-select" id="game-select" role="listbox" aria-label="Game selector">
<div class="select-btn" tabindex="0">
<span class="label"><?php echo ($filterGameId === 0) ? 'All games' : htmlspecialchars($gameMap[$filterGameId]['name'] ?? 'All games'); ?></span>
<svg class="arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" style="opacity:.9">
<path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
</div>
<div class="options" role="list">
<div class="opt" data-value="0">All games</div>
<?php foreach ($gameMap as $gid => $info): ?>
<div class="opt" data-value="<?php echo intval($gid); ?>"><?php echo htmlspecialchars($info['name'] ?? ('Game #' . $gid)); ?></div>
<?php endforeach; ?>
</div>
<input type="hidden" name="game_id" value="<?php echo intval($filterGameId); ?>">
</div>
<?php if ($filterGameId > 0): ?>
<a class="clear-link" href="<?php echo htmlspecialchars(MAIN_ROOT . 'competitions.php'); ?>">Clear</a>
<?php endif; ?>
</form>
</div>
<?php
if (empty($normalized)) {
echo "<div class='empty'>No events found.</div>";
} else {
echo "<div class='events-grid' id='events-grid'>";
foreach ($normalized as $item) {
// common
$title = htmlspecialchars($item['title']);
$game_name = htmlspecialchars($item['game_name'] ?? 'Unknown');
$event_type = $item['event_type'] == 1 ? "Online" : "Local";
$prize = ($item['prize'] !== '') ? htmlspecialchars($item['prize']) : '';
$prize_currency = ($item['prize_currency'] !== '') ? htmlspecialchars($item['prize_currency']) : '';
$img = htmlspecialchars($item['imageurl'] ?? '');
$startIso = htmlspecialchars($item['start_iso'] ?? '');
$serverStatus = htmlspecialchars($item['server_status'] ?? 'upcoming');
$countColor = htmlspecialchars($item['count_color'] ?? '#e6f8ff');
$url = htmlspecialchars($item['url'] ?? '#');
$awardsAssigned = $item['awards_assigned'] ?? false;


// build card anchor with all data-* attrs
echo "<a class='event-card' href='{$url}' data-start-utc='{$startIso}' data-server-status='{$serverStatus}' data-count-color='{$countColor}' data-awards-assigned='" . ($awardsAssigned ? '1' : '0') . "'>";
// cover image
if ($img) {
$imgSrc = (strpos($img, 'http') === 0) ? $img : MAIN_ROOT . ltrim($img, '/');
echo "<img class='cover' src='" . htmlspecialchars($imgSrc) . "' alt='" . $title . "'>";
} else {
echo "<div class='cover' style='height:160px;display:flex;align-items:center;justify-content:center;background:#061428;color:#9fb0c8;font-weight:700;'>No image</div>";
}
// card content
echo "<div class='card-body'>";
echo "<div class='card-title'>";
echo "<div class='left'><div class='subtitle'>{$game_name}</div><div class='title-main'>{$title}</div><div class='eventtype'>{$event_type}</div></div>";
echo "</div>";
echo "<div style='display:flex; align-items:center; justify-content:space-between;'>";
$parts = intval($item['participants'] ?? 0);
$max = intval($item['maxteams'] ?? 0);
echo "<div class='meta-compact'><div class='prize-inline'><span style='color:#9e9e9e; margin-bottom:7px;'>Participants</span>" . htmlspecialchars((string)$parts) . "/" . htmlspecialchars((string)$max) . "</div></div>";
echo "<div class='meta-compact'><div class='prize-inline'><span style='color:#9e9e9e; margin-bottom:7px;'>Prize</span>" . ($prize !== '' ? "{$prize} {$prize_currency}" : '') . "</div></div>";
echo "</div>";
echo "<div class='count-area'>";
// Status text above the countdown box
echo "<div class='status-text' data-status-text style='color:{$countColor};'></div>";
echo "<div class='countbox' data-countbox role='status' aria-live='polite'>";
echo "<div class='count-num' data-count-num style='color:{$countColor};'>—</div>";
echo "</div>";
echo "</div>"; // count-area
echo "</div>"; // card-body
echo "</a>";
}
echo "</div>"; // events-grid
}
?>
</div>
<script>
(function() {
    "use strict";
    /* unified custom select init */
    (function initCustomSelect(){
        var cs = document.getElementById('game-select');
        if (!cs) return;
        var btn = cs.querySelector('.select-btn');
        var opts = cs.querySelector('.options');
        var hidden = cs.querySelector('input[type="hidden"]');
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            cs.classList.toggle('open');
        });
        btn.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                cs.classList.toggle('open');
            } else if (e.key === 'Escape') {
                cs.classList.remove('open');
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
e.preventDefault();
var optsList = opts.querySelectorAll('.opt');
if (!optsList.length) return;
var current = cs.querySelector('.opt[aria-selected="true"]') || optsList[0];
var idx = Array.prototype.indexOf.call(optsList, current);
if (e.key === 'ArrowDown') idx = Math.min(idx + 1, optsList.length - 1);
else idx = Math.max(idx - 1, 0);
optsList[idx].focus();
optsList[idx].setAttribute('aria-selected','true');
}
        });
        opts.querySelectorAll('.opt').forEach(function(o){
            o.addEventListener('click', function(ev){
                var val = this.getAttribute('data-value') || '';
                var text = this.textContent || '';
                if (hidden) hidden.value = val;
                var label = cs.querySelector('.label');
                if (label) label.textContent = text;
                cs.classList.remove('open');
                var form = cs.closest('form');
                if (form) form.submit();
            });
        });
        document.addEventListener('click', function(){
            document.querySelectorAll('.custom-select.open').forEach(function(c){ c.classList.remove('open'); });
        });
    })();
    /* SIMPLIFIED status updater - Only 3 statuses */
    function pad(n){ return n < 10 ? '0' + n : '' + n; }
    function breakdown(ms) {
        if (!ms || ms <= 0) return { d:0, h:0, m:0, s:0 };
        var total = Math.floor(ms / 1000);
        var d = Math.floor(total / 86400);
        var h = Math.floor((total % 86400) / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        return { d:d, h:h, m:m, s:s };
    }
    function updateCard(card) {
        if (!card) return;
        var startIso = card.getAttribute('data-start-utc') || '';
        var serverStatus = (card.getAttribute('data-server-status') || 'upcoming').toLowerCase();
        var countColor = card.getAttribute('data-count-color') || '';
        var awardsAssigned = card.getAttribute('data-awards-assigned') === '1';
        
        var startDate = startIso ? new Date(startIso) : null;
        var now = new Date();
        
        var countbox = card.querySelector('[data-countbox]');
        var countNum = card.querySelector('[data-count-num]');
        var statusText = card.querySelector('[data-status-text]');

        if (countbox) countbox.style.display = '';
        if (statusText) statusText.style.color = countColor;

        // SIMPLIFIED LOGIC - Only 3 statuses
        if (awardsAssigned || serverStatus === 'finished') {
            // FINISHED
            if (countNum) { 
                countNum.textContent = 'Finished'; 
                countNum.style.color = '#6b7280'; 
            }
            if (statusText) statusText.textContent = 'Tournament Is Over';
            if (countbox) {
                countbox.classList.remove('upcoming', 'ongoing');
                countbox.classList.add('finished');
            }
            if (card) card.style.opacity = '0.4';
        } else if (serverStatus === 'ongoing') {
            // ONGOING - Simple text, no countdown
			if (countNum) {
				countNum.innerHTML = '<span class="ongoing-dot"></span>Ongoing';
			}
            if (statusText) statusText.textContent = 'Tournament Is Live';
            if (countNum) countNum.style.color = '#f59e0b';
            if (countbox) {
                countbox.classList.remove('upcoming', 'finished');
                countbox.classList.add('ongoing');
            }
        } else {
            // UPCOMING - Show countdown to start date
            if (countbox) {
                countbox.classList.remove('ongoing', 'finished');
                countbox.classList.add('upcoming');
            }
            
            if (startDate) {
                var diff = startDate.getTime() - now.getTime();
                if (diff < 0) diff = 0;
                var b = breakdown(diff);
                
                if (statusText) statusText.textContent = 'Registration ends in';
                if (countNum) {
                    countNum.textContent = (b.d > 0 ? b.d + 'd ' : '') + pad(b.h) + 'h ' + pad(b.m) + 'm ' + pad(b.s) + 's';
                    countNum.style.color = '#10b981';
                }
            } else {
                // No start date
                if (countNum) countNum.textContent = 'Upcoming';
                if (statusText) statusText.textContent = 'Status';
                if (countNum) countNum.style.color = '#10b981';
            }
        }
    }
    var cards = Array.prototype.slice.call(document.querySelectorAll('.event-card'));
    function tickAll() {
        cards.forEach(function(c){ updateCard(c); });
    }
    tickAll();
    setInterval(tickAll, 1000);
})();
</script>