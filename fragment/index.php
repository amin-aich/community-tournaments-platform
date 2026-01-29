<?php
// fragment/welcome.php
// Place this file at: fragment/welcome.php
$prevFolder = "../"; // adjust if your fragment folder is elsewhere
include_once($prevFolder . "_intro.php");

// if (isset($_SESSION['user_id'])) {
	// header("Location: ".$MAIN_ROOT."community.php");
	// exit();
// }

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
$PAGE_NAME = "Welcome To - ";

?>


		<!-- Hero Section -->
		<div style="text-align: center; margin: 80px 0;">
			<h1 style="font-size: 2.6rem; font-weight: bold; margin-bottom: 15px;">Welcome to DualMasters</h1>
			<p style="font-size: 1.2rem; color: #aaa;">A community-driven platform where gamers test their skills, earn victories, and collect rewards.</p>
		</div>

		<!-- How It Works Section -->
		<div style="margin: 80px 0;">
			<h2 style="font-size: 2rem; margin-bottom: 20px; text-align: center;">How It Works</h2>
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px;">

				<div style="background: #1110; border: 1px solid hsl(228deg 5% 34% / 63%); padding: 25px; border-radius: 8px; text-align: center;">
					<div style="font-size: 2rem; margin-bottom: 10px;">📝</div>
					<h3 style="margin-bottom: 10px;">Sign Up</h3>
					<p style="color: #aaa;">Create your gamer profile.</p>
				</div>

				<div style="background: #1110; border: 1px solid hsl(228deg 5% 34% / 63%); padding: 25px; border-radius: 8px; text-align: center;">
					<div style="font-size: 2rem; margin-bottom: 10px;">🎮</div>
					<h3 style="margin-bottom: 10px;">Join Tournaments</h3>
					<p style="color: #aaa;">Browse active competitions and sign up in one click.</p>
				</div>

				<div style="background: #1110; border: 1px solid hsl(228deg 5% 34% / 63%); padding: 25px; border-radius: 8px; text-align: center;">
					<div style="font-size: 2rem; margin-bottom: 10px;">🏆</div>
					<h3 style="margin-bottom: 10px;">Compete & Win</h3>
					<p style="color: #aaa;">Play against other gamers , earn XP, rewards, and community recognition.</p>
				</div>

			</div>
		</div>

		<!-- Call to Action -->
		<div style="text-align: center; margin: 80px 0;">
			<h2 style="font-size: 2rem; margin-bottom: 15px;">Ready to Compete?</h2>
			<p style="color: #aaa; margin-bottom: 25px;">Sign up and join the growing community of competitive gamers.</p>
			<a href="<?php echo htmlspecialchars($MAIN_ROOT); ?>competitions.php" style="display: inline-block; padding: 12px 30px; background: #0984e3; color: #fff; border-radius: 6px; text-decoration: none; font-weight: bold;">
				Explore Tournaments
			</a>
		</div>