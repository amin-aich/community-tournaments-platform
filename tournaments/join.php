<?php
// join.php (minimal) - updated to include code input (if tournament uses codes)
// and a game username (displayname) input prefilled from member's username.

include("../_intro.php");
$prevFolder = "../";
$PAGE_NAME = "Join - ";
include("../assets/_header.php");

if (!isset($_SESSION['user_id']) || !defined("LOGGED_IN")) {
    http_response_code(403);
    exit("Forbidden");
}

if (!isset($_GET['tID']) || !is_numeric($_GET['tID'])) {
    include($prevFolder . "assets/_footer.php");
    exit("Invalid tournament");
}

$tID = intval($_GET['tID']);
$tournamentObj = new Tournament($mysqli);
if (!$tournamentObj->select($tID)) {
    include($prevFolder . "assets/_footer.php");
    exit("Tournament not found");
}
$tournamentInfo = $tournamentObj->get_info();

// determine if tournament expects codes
$has_codes = !empty($tournamentInfo['codes']);

// fetch current member username to prefill displayname
$memberDisplay = '';
$mstmt = $mysqli->prepare("SELECT username FROM {$dbprefix}members WHERE member_id = ? LIMIT 1");
if ($mstmt) {
    $mstmt->bind_param('i', $_SESSION['user_id']);
    $mstmt->execute();
    $mres = $mstmt->get_result();
    if ($mrow = $mres->fetch_assoc()) {
        $memberDisplay = $mrow['username'] ?? '';
    }
    $mstmt->close();
}
?>
<main id="app-root" class="container" role="main">
  <div style="max-width:700px;margin:60px auto;padding:18px;border-radius:12px;border:1px solid #333;">
    <h2 style="margin:0 0 6px;color:#fff;">Register for: <?php echo htmlspecialchars($tournamentInfo['name']); ?></h2>
    <div style="color:#9aa3b2;margin-bottom:12px;">Participation is free. Join now if there are places available.</div>

    <div style="margin-bottom:12px;">
      <label style="display:block;margin-bottom:6px;color:#cfd8e3;">Game username (what other players will see)</label>
      <input id="displayname" type="text" maxlength="48" value="<?php echo htmlspecialchars($memberDisplay); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #444;background:#101214;color:#fff;">
    </div>

    <?php if ($has_codes): ?>
    <div style="margin-bottom:12px;">
      <label style="display:block;margin-bottom:6px;color:#cfd8e3;">Join Code (required)</label>
      <input id="joinCode" type="text" maxlength="32" placeholder="Enter your single-use join code" style="width:100%;padding:10px;border-radius:8px;border:1px solid #444;background:#101214;color:#fff;">
    </div>
    <?php endif; ?>

	<div id="formMessage" style="display:none;margin-bottom:12px;padding:10px;border-radius:6px;"></div>

    <button id="joinBtn" style="width:100%;padding:12px;border-radius:8px;border:none;background:#009688;color:#fff;font-weight:700;cursor:pointer;">
      Join Tournament
    </button>
  </div>
</main>

<script>
(function(){
  const joinBtn = document.getElementById('joinBtn');
  const formMessage = document.getElementById('formMessage');
  const CSRF_TOKEN = '<?php echo $_SESSION["csrftokken"] ?? ""; ?>';
  const hasCodes = <?php echo $has_codes ? 'true' : 'false'; ?>;

  function show(msg, ok=true) {
    formMessage.style.display = 'block';
    formMessage.style.color = ok ? '#b8f5c1' : '#fff';
    formMessage.style.background = ok ? 'rgba(40,110,40,0.12)' : 'rgb(191,60,60)';
    formMessage.innerText = msg;
  }

  joinBtn.addEventListener('click', async function(e){
    e.preventDefault();
    joinBtn.disabled = true;
    joinBtn.innerText = 'Registering...';

    const displayname = document.getElementById('displayname').value.trim();
    let code = '';
    if (hasCodes) {
      code = (document.getElementById('joinCode').value || '').trim();
      if (!code) {
        show('Code is required for this tournament', false);
        joinBtn.disabled = false;
        joinBtn.innerText = 'Join Tournament';
        return;
      }
    }

    try {
      const resp = await fetch('<?php echo $MAIN_ROOT; ?>tournaments/backend/join_action.php?tID=<?php echo $tID; ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, code: code, displayname: displayname })
      });
      const data = await resp.json();
      if (data.success) {
        show(data.message || 'Registered', true);
        joinBtn.style.display = 'none';
      } else {
        show(data.message || 'Failed to register', false);
        joinBtn.disabled = false;
        joinBtn.innerText = 'Join Tournament';
      }
    } catch (err) {
      show('Network error. Try again.', false);
      joinBtn.disabled = false;
      joinBtn.innerText = 'Join Tournament';
    }
  });
})();
</script>

<?php include($prevFolder . "assets/_footer.php"); ?>