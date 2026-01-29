<?php

// $actualPageNameLoc = strrpos($PAGE_NAME," - ");
// $actualPageName = substr($PAGE_NAME, 0, $actualPageNameLoc);

// if($PAGE_NAME == "") {
	// $actualPageName = "Unknown Page";
// }

if (!isset($_SESSION['user_id']) && !empty($_COOKIE['rememberme'])) {
	
	if (strpos($_COOKIE['rememberme'], ':') === false) {
		setcookie('rememberme', '', time() - 3600, '/');
		return;
	}
	
	list($selector, $token) = explode(':', $_COOKIE['rememberme'], 2);
	
    $stmt = $mysqli->prepare("SELECT member_id, token_hash, expires FROM {$dbprefix}remember_me_tokens WHERE selector = ? LIMIT 1");
    $stmt->bind_param("s", $selector);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($member_id, $token_hash, $expires);
        $stmt->fetch();

        // Check expiry first
        if (strtotime($expires) >= time()) {

            // Verify token
            if (hash_equals($token_hash, hash('sha256', $token))) {

                // ✅ Valid remember-me cookie → log in user
                $_SESSION['user_id'] = $member_id;

                // 🔒 Regenerate new token to prevent replay attacks
                $new_token = bin2hex(random_bytes(33));
                $new_token_hash = hash('sha256', $new_token);
                $new_expires = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 days

                $stmt_update = $mysqli->prepare("
                    UPDATE {$dbprefix}remember_me_tokens
                    SET token_hash = ?, expires = ?
                    WHERE selector = ?
                ");
                $stmt_update->bind_param("sss", $new_token_hash, $new_expires, $selector);
                $stmt_update->execute();

                // Update cookie with same selector but new token
                setcookie(
                    'rememberme',
                    $selector . ':' . $new_token,
                    time() + (86400 * 30),
                    '/',
                    '',
                    true,  // Secure flag (use HTTPS)
                    true   // HttpOnly flag
                );
            } else {
                // ❌ Token doesn't match → delete cookie
                setcookie('rememberme', '', time() - 3600, '/');
				$stmt_delete = $mysqli->prepare("DELETE FROM {$dbprefix}remember_me_tokens WHERE selector = ?");
				$stmt_delete->bind_param("s", $selector);
				$stmt_delete->execute();
				setcookie('rememberme', '', time() - 3600, '/');
            }
			
        } else {
            // ❌ Expired → delete cookie and DB entry
            $stmt_delete = $mysqli->prepare("DELETE FROM {$dbprefix}remember_me_tokens WHERE selector = ?");
            $stmt_delete->bind_param("s", $selector);
            $stmt_delete->execute();

            setcookie('rememberme', '', time() - 3600, '/');
        }
    }
}




if(isset($_SESSION['user_id'])) {
	
	$stmt = $mysqli->prepare("SELECT * FROM {$dbprefix}members WHERE member_id = ?");
	$stmt->bind_param("i", $_SESSION['user_id']);
	$stmt->execute();
	$result = $stmt->get_result();
	
	if($result->num_rows > 0) {
		
		define("LOGGED_IN", true);
		
		$row = $result->fetch_assoc();
		$stmt->close();
		
		$memberUsername = $row['username'];
		$row_member_id = $row['member_id'];
		$row_avatar = $row['avatar'];
		$memberRank = $row['rank_id'];
		
		$memberAvatar = '
			<img src="'.MAIN_ROOT.$row_avatar.'" width="26px" height="26px" style="border-radius: 25px;">
		';
		
		$memberAvatar2 = '
			<img src="'.MAIN_ROOT.$row_avatar.'" width="32px" height="32px" style="border-radius: 25px;">
		';
		
		$dropdown_arrow = '
			<img src="'.MAIN_ROOT.'assets/images/downarrow.png" style="position: absolute; right: -5px; bottom: 0px; border-radius: 25px; width: 12px; height: 12px;">
		';
		
		if($row['disabled'] == 1 OR $row['disabled'] == 2) {
			if(isset($_COOKIE['rememberme'])) {
				setcookie("rememberme", "", time()-(86400 * 30), $MAIN_ROOT);
			}
			unset($_SESSION['user_id']);
			if(isset($_SESSION['RememberMe'])) {
				unset($_SESSION['RememberMe']);
			}
		}
		
		$totalNewNTs = $mysqli->query("SELECT COUNT(*) FROM ".$dbprefix."notifications WHERE target_user_id = '$row_member_id' AND seen = 0")->fetch_row()[0];
		
		if($totalNewNTs > 0 and !defined("NOTIFS_PAGE") == true){
			$dispNTCount = $totalNewNTs;
			$badge_style = '';
		}else {
			$badge_style = ' display: none;';
			$dispNTCount = '';
		}
		
				// <a href="'.MAIN_ROOT.'chat.php" style="position: relative;" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment(\'' . htmlspecialchars(MAIN_ROOT . 'fragment/chat.php') . '\', true); else location.href=\'' . htmlspecialchars(MAIN_ROOT . 'chat.php') . '\';">
					// <img width="32px" height="32px" src="'.MAIN_ROOT.'assets/images/message.png">
					// <b style="position: absolute; top: -5px; right: -5px; background-color: #cc0000; color: white; border-radius: 10px; min-width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; padding: 0 6px; box-sizing: border-box; border: 2px solid white; display: none;" class="message-icon">
						
					// </b>
				// </a>
				
			// <a href="'.MAIN_ROOT.'chat.php" id="walletLink" style="position: relative;">
				// <img width="32px" height="32px" src="'.MAIN_ROOT.'assets/images/message.png">
				// <b style="position: absolute; top: -5px; right: -5px; background-color: #cc0000; color: white; border-radius: 10px; min-width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; padding: 0 6px; box-sizing: border-box; border: 2px solid white;display:none;" class="message-icon">
					
				// </b>
			// </a>
			
		
		$displayLogin = '
			
			<div class="headerRightItemsDiv">
				
				<a data-async href="'.MAIN_ROOT.'notifications.php" style="position: relative;" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment(\'' . htmlspecialchars(MAIN_ROOT . 'fragment/notifications.php') . '\', true); else location.href=\'' . htmlspecialchars(MAIN_ROOT . 'notifications.php') . '\';">
					<img style="width: 32px; height: 32px;" src="'.MAIN_ROOT.'assets/images/notification.png">
					<b style="position: absolute; top: -5px; right: -5px; background-color: #cc0000; color: white; border-radius: 10px; min-width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; padding: 0 6px; box-sizing: border-box; border: 2px solid white;'.$badge_style.'"  id="notificationBadge">
						'.$dispNTCount.'
					</b>
				</a>
				
				<div style="position: relative;">
					<button onclick="toggleUserDropdown(this)" class="dropdown-button" style="position: relative;">
						'.$memberAvatar2.$dropdown_arrow.'
					</button>
					
					<div id="userDropdown" class="dropdown-menu" style="display: none; right: -20px; top: 55px; width: 200px;">
						<a data-async href="'.MAIN_ROOT.'member.php?mID='.$row_member_id.'" class="dropdown-item" style="border-bottom: 1px solid rgba(0,0,0,0.1);" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment(\'' . htmlspecialchars(MAIN_ROOT . 'fragment/member.php?mID='.$row_member_id.'') . '\', true); else location.href=\'' . htmlspecialchars(MAIN_ROOT . 'member.php?mID='.$row_member_id.'') . '\';">
							<div style="display: flex; align-items: center; gap: 10px;">
								'.$memberAvatar.'
								<span>'.$memberUsername.'</span>
							</div>
						</a>
						
						<a data-async href="'.MAIN_ROOT.'edit-profile.php" class="dropdown-item" style="border-bottom: 1px solid rgba(0,0,0,0.1);">
							<div style="display: flex; align-items: center; gap: 10px;">
								<img width="20" height="20" src="'.MAIN_ROOT.'assets/images/edit.png" alt="Settings">
								<span>Edit Profile</span>
							</div>
						</a>
						
						<a href="'.MAIN_ROOT.'backend/signout.php" class="dropdown-item dropdown-item-danger">
							<div style="display: flex; align-items: center; gap: 10px;">
								<img width="20" height="20" src="'.MAIN_ROOT.'assets/images/signout.png" alt="Sign Out">
								<span>Sign Out</span>
							</div>
						</a>
					</div>
				</div>
			</div>
			
			<script>
				// For Solution 2 (class-based)
				function toggleUserDropdown(buttonElement) {
					const dropdown = buttonElement.nextElementSibling;
					const isVisible = dropdown.style.display === "block";
					dropdown.style.display = isVisible ? "none" : "block";
					if (!isVisible) {
						const clickHandler = function(e) {
							if (!dropdown.contains(e.target) && !e.target.closest(".dropdown-button")) {
								dropdown.style.display = "none";
								document.removeEventListener("click", clickHandler);
							}
						};
						setTimeout(() => document.addEventListener("click", clickHandler), 10);
					}
				}
				// Close when clicking any item (works for both solutions)
				document.querySelectorAll(".dropdown-item").forEach(item => {
					item.addEventListener("click", function() {
						this.closest(".dropdown-menu").style.display = "none";
					});
				});
			</script>
			
		';
	}
}

if(!defined("LOGGED_IN")) {
	
	$displayLogin = '
		<div class="headerRightItemsDiv">
			<a data-async href="'.MAIN_ROOT.'notifications.php" style="position: relative;" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment(\'' . htmlspecialchars(MAIN_ROOT . 'fragment/notifications.php') . '\', true); else location.href=\'' . htmlspecialchars(MAIN_ROOT . 'notifications.php') . '\';">
				<img style="width: 32px; height: 32px;" src="'.MAIN_ROOT.'assets/images/notification.png">
				<b class="nt_badge" style="display: none;" id="notificationBadge">
					
				</b>
			</a>
			
			<div style="position: relative;">
				<a data-async href="'.MAIN_ROOT.'auth.php" style="position: relative;" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment(\'' . htmlspecialchars(MAIN_ROOT . 'fragment/auth.php') . '\', true); else location.href=\'' . htmlspecialchars(MAIN_ROOT . 'auth.php') . '\';">
					<button id="loginBtn" class="btn" style="padding: 8px 14px;">
						LOG IN
					</button>
				</a>
			</div>
		</div>
	';
	
}

// ~2% chance per page load
if (rand(1, 50) === 1) {
    $mysqli->query("DELETE FROM {$dbprefix}request_logs WHERE created_at < (NOW() - INTERVAL 1 DAY)");
	$mysqli->query("DELETE FROM {$dbprefix}remember_me_tokens WHERE expires < NOW()");
}

?>

<html>

<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
	
	<meta name="description" content="">
    <meta name="keywords" content="">
    
	<base href="<?php echo htmlspecialchars($MAIN_ROOT); ?>">
	
	<title><?php echo $PAGE_NAME.$DOMAIN_NAME; ?></title>
	
	<link rel="stylesheet" type="text/css" href="<?php echo $MAIN_ROOT;?>assets/css/style.css">
</head>

<body class='body' id='body'>
	<div class='afterheaderD'></div>
	
	<div class='headerD' style='position: fixed; top: 0px; right: 0px; left: 0px; z-index: 9999;'>
		<div class='headerDiv'>
			
			<div class='headerItemsDiv'>
				
				<a data-async href="<?= htmlspecialchars($MAIN_ROOT . '') ?>" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment('<?= htmlspecialchars($MAIN_ROOT . 'fragment/index.php') ?>', true); else location.href='<?= htmlspecialchars($MAIN_ROOT . '') ?>';" style="font-size: 20px;">
					<!-- DualMasters -->
					<img src="<?php echo htmlspecialchars($MAIN_ROOT); ?>assets/images/logo.png" width="36" height="36" />
				</a>
				
				<a data-async href="<?= htmlspecialchars($MAIN_ROOT . 'competitions.php') ?>" <?php if(defined("COMPETITIONS_LINK")) { echo "class='link_active'"; }?> style="color: #fff" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment('<?= htmlspecialchars($MAIN_ROOT . 'fragment/competitions.php') ?>', true); else location.href='<?= htmlspecialchars($MAIN_ROOT . 'competitions.php') ?>';">
					<div class='headerItem' style='padding: 10px 15px;'><b>Competitions</b></div>
				</a>
				
			</div>
			
			<?php echo $displayLogin; ?>
			
		</div>
	</div>

	
	<div class='afterheaderM'></div>
	
	<div class='headerM' style='position: fixed; top: 0px; right: 0px; left: 0px; z-index: 999;'>
		
		<div class='headerDivM' style='display: flex; justify-content: space-between; align-items: center;'>
			
			<div class='headerItemsDivM'>
				
				<a data-async href="<?= htmlspecialchars($MAIN_ROOT . '') ?>" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment('<?= htmlspecialchars($MAIN_ROOT . 'fragment/index.php') ?>', true); else location.href='<?= htmlspecialchars($MAIN_ROOT . '') ?>';" style="font-size: 20px;">
					<!-- DualMasters -->
					<img src="<?php echo htmlspecialchars($MAIN_ROOT); ?>assets/images/logo.png" width="36" height="36" />
				</a>
				
				<a data-async href="<?= htmlspecialchars($MAIN_ROOT . 'competitions.php') ?>" <?php if(defined("COMPETITIONS_LINK")) { echo "class='link_active'"; }?> style="color: #fff" onclick="event.preventDefault(); if (window.fetchFragment) window.fetchFragment('<?= htmlspecialchars($MAIN_ROOT . 'fragment/competitions.php') ?>', true); else location.href='<?= htmlspecialchars($MAIN_ROOT . 'competitions.php') ?>';">
					<div class='headerItem' style='padding: 0px 0px;'>
						<img
						    width="32" height="32"
						    data-active-src="<?php echo MAIN_ROOT; ?>assets/images/tournament_blue.png"
						    data-inactive-src="<?php echo MAIN_ROOT; ?>assets/images/tournament.png"
						    src="<?php echo MAIN_ROOT; ?>assets/images/tournament.png"
						    alt="tournament icon"
						/>
					</div>
				</a>
				
			</div>
			
			<?php echo $displayLogin; ?>
			
		</div>
		
	</div>