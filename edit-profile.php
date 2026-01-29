<?php

// Config File
include_once("_intro.php");

$prevFolder = "";

// $PAGE_NAME = "Edit Profile - ";
$PAGE_NAME = "";
include("assets/_header.php");

if(!isset($_SESSION['user_id'])) {
	echo "<script>window.location = '".$MAIN_ROOT."'</script>";
	exit();
}

$stmt = $mysqli->prepare("SELECT profilepic, username, bio, country, facebook, twitch, youtube FROM {$dbprefix}members WHERE member_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if($result->num_rows == 0) {
	echo "<script>window.location = '".$MAIN_ROOT."'</script>";
	exit();
}
$row = $result->fetch_assoc();
$stmt->close();

?>

<main id="app-root" class="container" role="main" tabindex="-1">

<script>
(function () {
    // Server-inserted values
    const CSRF = <?php echo json_encode($_SESSION['csrftokken'] ?? $_SESSION['csrf_token'] ?? ''); ?>;
    const SITE_ROOT = <?php echo json_encode(rtrim($MAIN_ROOT, '/') . '/'); ?>;
    
    // Single endpoint for all profile actions
    const PROFILE_API = SITE_ROOT + 'backend/profile.php';

    // Server-side limits (in bytes) injected from PHP (falls back to 2MB)
    const SERVER_MAX_BYTES = <?php
        $ini = ini_get('upload_max_filesize') ?: '2M';
        $limitMB = (int)filter_var($ini, FILTER_SANITIZE_NUMBER_INT);
        if ($limitMB <= 0) $limitMB = 2;
        echo ($limitMB * 1048576);
    ?>;

    // Client-side dimension constraints (mirror PHP)
    const MIN_DIM = 16;
    const MAX_DIM = 8000;

    // Allowed MIME map (mirror PHP's allowed_mimes)
    const ALLOWED_MIMES = ['image/gif', 'image/jpeg', 'image/png'];

    // Small helpers
    function el(id){ return document.getElementById(id); }
    function qs(sel){ try { return document.querySelector(sel); } catch(e) { return null; } }
    function notify(msg, type){ if (typeof showNotification === 'function') showNotification(msg, type); else console.log((type||'info')+': '+msg); }

    // Generic safe fetch which tolerates non-OK and returns parsed JSON or throws with serverData
    async function safeFetchJSON(url, opts = {}) {
        opts.credentials = opts.credentials || 'same-origin';
        const res = await fetch(url, opts).catch(e => { throw new Error('Network error'); });
        const text = await res.text().catch(()=> '');
        let data = {};
        try { data = text ? JSON.parse(text) : {}; } catch(e) { throw new Error('Invalid JSON from server: ' + text); }
        if (!res.ok) {
            const err = new Error(data.globalError || data.message || ('HTTP ' + res.status));
            err.serverData = data;
            throw err;
        }
        return data;
    }

    // Field error helpers (same style as auth-core)
    function clearField(id) {
        if(!id) return;
        // try common error spans (camelCase and dash-case)
        const e1 = el(id + 'Error');
        const e2 = el(id + '-error');
        if (e1) e1.innerHTML = '';
        if (e2) e2.innerHTML = '';
        const i = el(id);
        if (i) i.style.borderColor = '#52545ba1';
    }
    function setFieldError(id, text) {
        if(!id) return;
        const e1 = el(id + 'Error');
        const e2 = el(id + '-error');
        const errorHtml = `<p style="color:#ff6b6b;font-size:12px">${text}</p>`;
        if (e1) e1.innerHTML = errorHtml;
        else if (e2) e2.innerHTML = errorHtml;
        else {
            // fallback: try to append error span after element
            const input = el(id);
            if (input && !el(id + '__auto_err')) {
                const span = document.createElement('div');
                span.id = id + '__auto_err';
                span.innerHTML = errorHtml;
                span.style.marginTop = '4px';
                input.parentNode && input.parentNode.appendChild(span);
            }
        }
        const i = el(id);
        if (i) i.style.borderColor = '#c15755';
    }
    function applyFieldErrors(errors) {
        if (!errors) return;
        Object.keys(errors).forEach(k => setFieldError(k, errors[k]));
    }

    // Validate an image file on the client using similar guards as PHP.
    function validateImageFile(file) {
        return new Promise((resolve, reject) => {
            if (!file) return reject('No file selected.');
            if (!(file instanceof File)) return reject('Invalid file object.');

            // Quick client-side type check
            const mime = file.type || '';
            if (!mime.startsWith('image/')) {
                return reject('File must be an image.');
            }
            if (!ALLOWED_MIMES.includes(mime)) {
                return reject('Only GIF, JPEG, or PNG images allowed.');
            }

            // Size check vs server limit
            if (file.size > SERVER_MAX_BYTES) {
                const mb = Math.round(SERVER_MAX_BYTES / 1048576);
                return reject('File too large (max ' + mb + ' MB).');
            }

            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function() {
                const w = img.naturalWidth || img.width;
                const h = img.naturalHeight || img.height;
                URL.revokeObjectURL(url);
                if (typeof w !== 'number' || typeof h !== 'number') return reject('Unable to determine image dimensions.');
                if (w < MIN_DIM || h < MIN_DIM) return reject('Avatar too small (min ' + MIN_DIM + 'x' + MIN_DIM + ').');
                if (w > MAX_DIM || h > MAX_DIM) return reject('Image dimensions too large (max ' + MAX_DIM + 'x' + MAX_DIM + ').');
                resolve({ width: w, height: h, mime: mime, size: file.size });
            };
            img.onerror = function() {
                URL.revokeObjectURL(url);
                reject('Uploaded file is not a valid image.');
            };
            img.src = url;
        });
    }

    // Re-usable POST JSON helper for profile route
    async function postProfileJSON(payload) {
        const res = await safeFetchJSON(PROFILE_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        return res;
    }

    // ---------------- Initialization and handlers ----------------
    function initializeAll() {
        // ---------- Avatar preview + upload ----------
        const fileInput = el('fileUpload');
        const avatarPreview = el('avatarPreview');
        const previewBtnWrap = el('avatar-preview-button');
        const changeAvatarBtn = el('changeAvatar');

        if (fileInput && avatarPreview) {
            fileInput.addEventListener('change', function(){
                const file = this.files && this.files[0];
                if (!file) { 
                    if (previewBtnWrap) previewBtnWrap.style.display='none'; 
                    return; 
                }

                if (!file.type || !file.type.startsWith('image/')) {
                    notify('Selected file is not an image.', 'error');
                    fileInput.value = '';
                    if (previewBtnWrap) previewBtnWrap.style.display='none';
                    return;
                }
                if (file.size > SERVER_MAX_BYTES) {
                    const mb = Math.round(SERVER_MAX_BYTES / 1048576);
                    notify('File too large (max ' + mb + ' MB).', 'error');
                    fileInput.value = '';
                    if (previewBtnWrap) previewBtnWrap.style.display='none';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e){ avatarPreview.src = e.target.result; };
                reader.readAsDataURL(file);
                if (previewBtnWrap) previewBtnWrap.style.display = 'flex';
            });
        }

        if (changeAvatarBtn) {
            changeAvatarBtn.addEventListener('click', async function(){
                const selectedFile = fileInput && fileInput.files && fileInput.files[0];
                if (!selectedFile) { 
                    notify('Please select an image first!', 'error'); 
                    return; 
                }

                // clear previous errors
                clearField('fileUpload');
                try {
                    changeAvatarBtn.disabled = true;
                    const btnSpan = changeAvatarBtn.querySelector('.button-text');
                    if (btnSpan) btnSpan.textContent = 'VALIDATING...';
                    await validateImageFile(selectedFile);
                } catch (errMsg) {
                    notify(errMsg || 'Image validation failed.', 'error');
                    changeAvatarBtn.disabled = false;
                    const btnSpan2 = changeAvatarBtn.querySelector('.button-text');
                    if (btnSpan2) btnSpan2.textContent = 'UPDATE';
                    return;
                }

                const fd = new FormData();
                fd.append('image', selectedFile);
                fd.append('csrf_token', CSRF);
                fd.append('action', 'avatar');

                changeAvatarBtn.disabled = true;
                const btnSpan = changeAvatarBtn.querySelector('.button-text');
                if (btnSpan) btnSpan.textContent = 'UPLOADING...';

                try {
                    // For FormData we can't use safeFetchJSON helper (it sets JSON headers). So handle separately.
                    const res = await fetch(PROFILE_API, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    });
                    const text = await res.text();
                    let data = {};
                    try { data = text ? JSON.parse(text) : {}; } catch(e) { throw new Error('Invalid JSON from server: ' + text); }
                    if (!res.ok) {
                        const err = new Error(data.globalError || data.message || ('HTTP ' + res.status));
                        err.serverData = data;
                        throw err;
                    }

                    if (data.status === 'success') {
                        notify(data.message || data.msg || 'Avatar updated', 'success');
                        if (data.new_profilepic) avatarPreview.src = data.new_profilepic;
                        else if (data.thumb) avatarPreview.src = data.thumb.startsWith('http') ? data.thumb : (SITE_ROOT + data.thumb);
                    } else {
                        if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
                        else if (data.globalError) notify(data.globalError, 'error');
                        else notify(data.message || 'Error uploading avatar', 'error');
                    }
                } catch (err) {
                    console.error('Avatar upload error', err);
                    const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
                    notify(msg, 'error');
                } finally {
                    changeAvatarBtn.disabled = false;
                    if (btnSpan) btnSpan.textContent = 'UPDATE';
                }
            });
        }

        // ---------- Username update ----------
        const editUsernameBtn = el('editUsername');
        const usernameInput = el('username');
        if (editUsernameBtn && usernameInput) {
            editUsernameBtn.addEventListener('click', async function(){
                clearField('username');
                const username = (usernameInput.value || '').trim();

                let isValid = true;
                let errorMsg = '';

                if (!/^[a-zA-Z0-9._]+$/.test(username)) {
                    isValid = false;
                    errorMsg = "Username can only contain letters, numbers, dots, and underscores.";
                } else if (username.length < 2 || username.length > 30) {
                    isValid = false;
                    errorMsg = "Username must be 2–30 characters long.";
                } else if (!/^[a-zA-Z0-9].*[a-zA-Z0-9]$/.test(username)) {
                    isValid = false;
                    errorMsg = "Username must start and end with a letter or number.";
                } else if (/[_.]{2,}/.test(username)) {
                    isValid = false;
                    errorMsg = "Username cannot contain consecutive dots or underscores.";
                } else if (username.length < 4) {
                    isValid = false;
                    errorMsg = "Username is too short.";
                } else if (username.length > 50) {
                    isValid = false;
                    errorMsg = "Username is too long.";
                } else if (username === '') {
                    isValid = false;
                    errorMsg = "Please enter a username.";
                }

                if (!isValid) {
                    setFieldError('username', errorMsg);
                    return;
                }

                try {
                    editUsernameBtn.disabled = true;
                    const prev = editUsernameBtn.textContent;
                    editUsernameBtn.textContent = 'UPDATING...';
                    const data = await postProfileJSON({ action: 'username', username, csrf_token: CSRF });

                    if (data.status === 'success') {
                        notify(data.message || data.msg || 'Username updated', 'success');
                    } else {
                        if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
                        else if (data.globalError) notify(data.globalError, 'error');
                        else notify(data.message || 'Error updating username', 'error');
                    }
                } catch (err) {
                    console.error('Username update error', err);
                    const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
                    notify(msg, 'error');
                } finally {
                    editUsernameBtn.disabled = false;
                    editUsernameBtn.textContent = 'UPDATE';
                }
            });
        }

        // ---------- Social links ----------
        const saveSocialsBtn = el('saveSocials');
        if (saveSocialsBtn) {
            saveSocialsBtn.addEventListener('click', async function(){
                ['facebook','twitch','youtube'].forEach(clearField);
                const facebook = (el('facebook') && el('facebook').value || '').trim();
                const twitch = (el('twitch') && el('twitch').value || '').trim();
                const youtube = (el('youtube') && el('youtube').value || '').trim();

                // local validation (same rules)
                function extractHandle(input) {
                    const s = input.trim();
                    if (s === '') return null;
                    if (s.startsWith('http') || s.startsWith('www.')) {
                        try {
                            const url = new URL(s.startsWith('http') ? s : 'https://' + s);
                            const path = url.pathname.replace(/^\/+|\/+$/g, '');
                            if (path === '') return null;
                            const parts = path.split('/');
                            return parts[parts.length - 1];
                        } catch (e) {
                            return null;
                        }
                    }
                    return s.startsWith('@') ? s.substring(1) : s;
                }

                function validFacebook(h) { if (h === null) return true; return /^[A-Za-z0-9.]{3,100}$/.test(h); }
                function validTwitch(h) { if (h === null) return true; return /^[a-z0-9_]{4,25}$/i.test(h); }
                function validYoutube(h) { if (h === null) return true; if (h.startsWith('UC')) return /^UC[0-9A-Za-z_\-]{22}$/.test(h); return /^[A-Za-z0-9_\-]{2,100}$/.test(h); }

                const facebook_h = facebook !== '' ? extractHandle(facebook) : null;
                const twitch_h = twitch !== '' ? extractHandle(twitch) : null;
                const youtube_h = youtube !== '' ? extractHandle(youtube) : null;

                if (!validTwitch(twitch_h)) { setFieldError('twitch', 'Invalid Twitch username'); return; }
                if (!validFacebook(facebook_h)) { setFieldError('facebook', 'Invalid Facebook handle'); return; }
                if (!validYoutube(youtube_h)) { setFieldError('youtube', 'Invalid YouTube handle or channel ID'); return; }

                try {
                    saveSocialsBtn.disabled = true;
                    const prev = saveSocialsBtn.textContent;
                    saveSocialsBtn.textContent = 'SAVING...';
                    const data = await postProfileJSON({ action: 'socials', facebook, twitch, youtube, csrf_token: CSRF });

                    if (data.status === 'success') {
                        notify(data.message || data.msg || 'Social links saved', 'success');
                    } else {
                        if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
                        else if (data.globalError) notify(data.globalError, 'error');
                        else notify(data.message || 'Error saving socials', 'error');
                    }
                } catch (err) {
                    console.error('Socials save error', err);
                    const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
                    notify(msg, 'error');
                } finally {
                    saveSocialsBtn.disabled = false;
                    saveSocialsBtn.textContent = 'UPDATE';
                }
            });
        }

        // ---------- Password update ----------
        const editPasswordBtn = el('editPassword');
        if (editPasswordBtn) {
            editPasswordBtn.addEventListener('click', async function(){
                ['password','newPassword'].forEach(clearField);
                const password = (el('password') && el('password').value) || '';
                const newPassword = (el('newPassword') && el('newPassword').value) || '';

                // basic client-side password checks (mirrors the checklist)
                if (!newPassword || newPassword.length < 10) {
                    setFieldError('newPassword', 'Password must be at least 10 characters long.');
                    return;
                }

                try {
                    editPasswordBtn.disabled = true;
                    const prev = editPasswordBtn.textContent;
                    editPasswordBtn.textContent = 'UPDATING...';
                    const data = await postProfileJSON({ action: 'password', current_pass: password, new_pass: newPassword, csrf_token: CSRF });

                    if (data.status === 'success') {
                        notify(data.message || data.msg || 'Password updated', 'success');
                        if (el('password')) el('password').value = '';
                        if (el('newPassword')) el('newPassword').value = '';
                    } else {
                        if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
                        else if (data.globalError) notify(data.globalError, 'error');
                        else notify(data.message || 'Error updating password', 'error');
                    }
                } catch (err) {
                    console.error('Password update error', err);
                    const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
                    notify(msg, 'error');
                } finally {
                    editPasswordBtn.disabled = false;
                    editPasswordBtn.textContent = 'UPDATE';
                }
            });
        }

        // ---------- Country save ----------
        const saveCountryBtn = el('saveCountryBtn');
        if (saveCountryBtn) {
            saveCountryBtn.addEventListener('click', async function(){
                clearField('countryInput');
                const country = (el('countryInput') && el('countryInput').value) || '';
                saveCountryBtn.disabled = true;
                const prevText = saveCountryBtn.textContent;
                saveCountryBtn.textContent = 'SAVING...';

                try {
                    const data = await postProfileJSON({ action: 'country', country, csrf_token: CSRF });

                    if (data.status === 'success') {
                        notify(data.message || data.msg || 'Country saved', 'success');
                        if (data.flag_url) {
                            const label = document.querySelector('#country-select .select-btn .label');
                            if (label) {
                                label.innerHTML = (data.flag_url ? ('<img src="'+data.flag_url+'" class="flag" style="vertical-align:middle;"> ') : '') + (data.country_name || label.textContent.trim());
                            }
                        }
                    } else {
                        if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
                        else if (data.globalError) notify(data.globalError, 'error');
                        else notify(data.message || 'Error saving country', 'error');
                    }
                } catch (err) {
                    console.error('Save country error:', err);
                    const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
                    notify(msg, 'error');
                } finally {
                    saveCountryBtn.disabled = false;
                    saveCountryBtn.textContent = prevText;
                }
            });
        }
		
		// ---------- Bio update ----------
		const saveBioBtn = el('saveBio');
		const bioInput = el('bio');
		const bioCharCount = el('bioCharCount');

		if (bioInput && bioCharCount) {
			// Character counter
			bioInput.addEventListener('input', function() {
				const length = this.value.length;
				bioCharCount.textContent = length;
				
				// Optional: Change color when approaching limit
				if (length > 450) {
					bioCharCount.style.color = '#ff6b6b';
				} else {
					bioCharCount.style.color = '#666';
				}
			});
			
			// Initialize character count
			bioCharCount.textContent = bioInput.value.length;
		}

		if (saveBioBtn && bioInput) {
			saveBioBtn.addEventListener('click', async function() {
				clearField('bio');
				const bio = bioInput.value.trim();

				// Client-side validation
				const max_length = 500;
				
				if (bio.length > max_length) {
					const errorMsg = `Bio is too long (max ${max_length} characters).`;
					setFieldError('bio', errorMsg);
					return;
				}

				// Optional: Basic content validation
				const disallowedPatterns = [
					/<script\b[^>]*>(.*?)<\/script>/is,
					/javascript:/i,
					/on\w+\s*=/i
				];

				for (const pattern of disallowedPatterns) {
					if (pattern.test(bio)) {
						const errorMsg = 'Bio contains disallowed content.';
						setFieldError('bio', errorMsg);
						return;
					}
				}

				// UI feedback
				saveBioBtn.disabled = true;
				const btnSpan = saveBioBtn.querySelector('.button-text');
				if (btnSpan) btnSpan.textContent = 'UPDATING...';

				try {
					const data = await postProfileJSON({ action: 'bio', bio, csrf_token: CSRF });

					if (data.status === 'success') {
						notify(data.message || data.msg || 'Bio updated successfully!', 'success');
					} else {
						if (data.fieldErrors) applyFieldErrors(data.fieldErrors);
						else if (data.globalError) notify(data.globalError, 'error');
						else notify(data.message || 'Error updating bio', 'error');
					}
				} catch (err) {
					console.error('Bio update error:', err);
					const msg = (err && err.serverData && err.serverData.globalError) ? err.serverData.globalError : err.message || 'Network error';
					notify(msg, 'error');
				} finally {
					saveBioBtn.disabled = false;
					if (btnSpan) btnSpan.textContent = 'UPDATE BIO';
				}
			});
		}

        // Initialize password UI checklist and toggles if you still want them
        // (we kept your existing initializePasswordValidation & toggles in the page below)
    }

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAll);
    } else {
        setTimeout(initializeAll, 0);
    }

})(); // end IIFE
</script>

<!-- keep all your existing styles and markup below - unchanged -->
<style>
.custom-select { position:relative; min-width:220px; }
.custom-select .select-btn { display:flex; align-items:center; justify-content:space-between; padding:12px; border-radius:8px; background:#071428; color:#e6eef8; cursor:pointer; border:1px solid rgba(255,255,255,0.04); }
.custom-select .options { position:absolute; top:calc(100% + 8px); left:0; right:0; background:#021124; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.6); z-index:50; display:none; max-height:220px; overflow:auto; }
.custom-select.open .options { display:block; }
.custom-select .options .opt { padding:8px 10px; cursor:pointer; border-bottom:1px solid rgba(255,255,255,0.02); color:#cfe6ff; display:flex; align-items:center; gap:10px; }
.custom-select .flag { width:20px; height:15px; object-fit:cover; border-radius:2px; }
.custom-select .options .opt:hover { background:rgba(255,255,255,0.02); }
.custom-select .arrow { margin-left:12px; transform:rotate(0deg); transition:transform .15s ease; }
.custom-select.open .arrow { transform:rotate(180deg); }
.char-counter { display:flex; align-items:center; justify-content:space-between; text-align:right; font-size:12px; color:#666; margin-top: 0px; }
.char-counter.warning { color:#ff6b6b; }
#bio { resize:vertical; min-height:80px; font-family:inherit; line-height:1.4; }
#bio.error { border-color:#c15755; color:#c15755; }
</style>


	<div class='form-section'>
		<div class='form-section-form'>
			
			<h2 class="heading-title" style="font-size: 2.4rem; margin:10px 0 50px 0">Edit Profile</h2>
			
			<!-- Avatar Preview + Upload Area -->
			<div class="avatar-upload-container">
				<div class="avatar-preview">
					<img src='<?= $MAIN_ROOT . $row['profilepic'] ?>' id="avatarPreview">
					<label for="fileUpload" class="upload-label">
						<img src="assets/images/picture.png" class="upload-icon">
					</label>
				</div>
				
				<input type="file" id="fileUpload" accept="image/*" hidden>
				
				<div id="avatar-preview-button" style="display: none;">
					<button class="btn" id="changeAvatar" style="padding: 10 20px;">
						<span class="button-text">UPDATE</span>
					</button>
				</div>
				
			</div>
			
			<div style='margin-top: 50px;'>
				<div class="input-group">
					<label class='input-group-label'>Username</label>
					<input type='text' class='input-group-input' id='username' value='<?php echo $row['username']; ?>' placeholder='username' onfocus="(function(){ const e = document.getElementById('username'); if(e) e.style.color='#ffffff'; })();">
					<span id="usernameError" style='position: absolute'></span>
				</div>
				<div style='display: flex; justify-content: flex-end; margin-top:30px;'>
					<button type='button' id='editUsername' class='btn' style='padding: 10px 20px;'>UPDATE</button>
				</div>
			</div>
			
			<div style="margin-top: 50px;">
				<div class="input-group" style="margin-bottom:18px;">
					<label class='input-group-label'>Bio</label>
					<textarea 
						id="bio"
						class="textInput formInput" 
						placeholder="Tell people about yourself..." 
						maxlength="500"
						rows="4"
					><?php echo htmlspecialchars($row['bio'] ?? ''); ?></textarea>
					<div class="char-counter">
						<div id="bioError" class="error-message"></div>
						<div><span id="bioCharCount">0</span>/500</div>
					</div>
				</div>
				<div style='display: flex; justify-content: flex-end; margin-top:18px;'>
					<button type='button' id='saveBio' class='btn' style='padding: 10px 20px;'><span class="button-text">UPDATE</span></button>
				</div>
			</div>
			
			<?php
			// SERVER: small country list used by both front-end and backend
			$countries = [
			  '' => 'Not set',
			  'US'=>'United States','GB'=>'United Kingdom','CA'=>'Canada','DE'=>'Germany','FR'=>'France',
			  'ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','AU'=>'Australia',
			  'IN'=>'India','JP'=>'Japan','CN'=>'China','DZ'=>'Algeria','MA'=>'Morocco','EG'=>'Egypt'
			  // extend this list as needed
			];

			$currentCountry = $row['country'] ?? '';
			$flagsBaseUrl = $MAIN_ROOT . 'assets/images/flags/'; // front-end URL to flags
			$flagsDir = $MAIN_ROOT . '/assets/images/flags/';      // server path for file_exists checks (optional)
			?>

			<!-- Country selector -->
			<div style="margin-top:50px;">
			  <label class='input-group-label' style="display:block;">Country</label>
			  <div class="custom-select" id="country-select" role="listbox" aria-label="Country selector" tabindex="0">
				<div class="select-btn" tabindex="0">
				  <span class="label" style="display:flex;align-items:center;gap:10px;">
				    <?php
					  $label = $countries[$currentCountry] ?? 'Not set';
					  if ($currentCountry && $currentCountry !== '') {
					    echo '<img src="' . $flagsBaseUrl . $currentCountry . '.png" class="flag" style="vertical-align:middle;">';
					  }
					  echo htmlspecialchars($label, ENT_QUOTES);
				    ?>
				  </span>
				  <svg class="arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" style="opacity:.9"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>

				<div class="options" role="list">
				    <?php foreach ($countries as $code => $label): 
					  // Only show flag image if country code is not empty
					  $imgHtml = ($code !== '') ? '<img src="'. $flagsBaseUrl . $code .'.png" class="flag" alt="'.htmlspecialchars($code).'">' : '';
				    ?>
				    <div class="opt" data-value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" role="option" aria-selected="<?php echo ($code === $currentCountry) ? 'true' : 'false'; ?>">
					  <?php echo $imgHtml; ?><?php echo htmlspecialchars($label, ENT_QUOTES); ?>
				    </div>
				    <?php endforeach; ?>
				</div>

				<input type="hidden" id="countryInput" name="country" value="<?php echo htmlspecialchars($currentCountry, ENT_QUOTES); ?>">
			  </div>

			  <div style="display: flex; justify-content: flex-end; margin-top:30px;">
				<button type="button" id="saveCountryBtn" class="btn" style="padding:10px 20px;">UPDATE</button>
			  </div>
			</div>
			
			<div id='form-socials' style='margin-top: 50px;'>
			  <div class="input-group" style='position: relative;'>
				<div style="display: flex; align-items: center; gap: 10px;  position: relative;">
				  <img src='assets/images/socialmedias/facebook.png' style=''>
				  <input type='text' id='facebook' class='input-group-input' value='<?php echo htmlspecialchars($row['facebook'] ?? '', ENT_QUOTES); ?>' placeholder='username or full URL'>
				</div>
				<span id="facebookError" style='color:#c15755;font-size:12px'></span>
			  </div>

			  <div class="input-group" style='position: relative;'>
				<div style="display: flex; align-items: center; gap: 10px;  position: relative;">
				  <img src='assets/images/socialmedias/twitch.png' style=''>
				  <input type='text' id='twitch' class='input-group-input' value='<?php echo htmlspecialchars($row['twitch'] ?? '', ENT_QUOTES); ?>' placeholder='username or full URL'>
				</div>
				<span id="twitchError" style='color:#c15755;font-size:12px'></span>
			  </div>

			  <div class="input-group" style='position: relative;'>
				<div style="display: flex; align-items: center; gap: 10px;  position: relative;">
				  <img src='assets/images/socialmedias/youtube.png' style=''>
				  <input type='text' id='youtube' class='input-group-input' value='<?php echo htmlspecialchars($row['youtube'] ?? '', ENT_QUOTES); ?>' placeholder='@handle, channel ID or full URL'>
				</div>
				<span id="youtubeError" style='color:#c15755;font-size:12px'></span>
			  </div>

			  <div style='display: flex; justify-content: flex-end; margin-top:30px;'>
				<button type='button' id='saveSocials' class='btn' style='padding:10px 20px;'>UPDATE</button>
			  </div>
			</div>
			
			<div style='margin-top: 50px;'>
				<div class="input-group">
					<label class='input-group-label'>Current Password</label>
					<div style="display: flex; align-items: center; gap: 10px;  position: relative;">
						<input type='password' class='input-group-input' id='password'>
						<img src='assets/images/passwordshow.png' id='eyeIcon' style='width: 35px; position: absolute; right: 1px; top: 5px; z-index: 10; cursor: pointer;'>
					</div>
					<span id="passwordError" style=''></span>
				</div>
				
				<div class="input-group">
					<label class='input-group-label'>New Password</label>
					<div style="display: flex; align-items: center; gap: 10px;  position: relative;">
						<input type='password' class='input-group-input' id='newPassword'>
						<img src='assets/images/passwordshow.png' id='newEyeIcon' style='width: 35px; position: absolute; right: 1px; top: 5px; z-index: 10; cursor: pointer;'>
					</div>
					<span id="newPasswordError" style=''></span>
				</div>
				
				<div style='display: flex; justify-content: flex-end; margin-top:30px;'>
					<button type='button' id='editPassword' class='btn' style='padding: 10px 20px;'>UPDATE</button>
				</div>
				
				<br>
				
			</div>
			
		</div>
	</div>


<script>
    // small password toggle + checklist init (kept from your original; uses same ids)
    (function(){
        function setupToggle(eyeId, inputId){
            const eye = document.getElementById(eyeId); 
            const input = document.getElementById(inputId);
            if(!eye || !input) return;
            eye.addEventListener('click', function(){
                if (input.type === 'password') { 
                    input.type = 'text'; 
                    eye.src = 'assets/images/passwordhide.png'; 
                } else { 
                    input.type = 'password'; 
                    eye.src = 'assets/images/passwordshow.png'; 
                }
            });
        }
        setupToggle('eyeIcon','password');
        setupToggle('newEyeIcon','newPassword');

        // basic password checklist UI (if present)
        function initializePasswordValidation() {
            const newPasswordInput = document.getElementById('newPassword');
            const newPasswordError = document.getElementById('newPasswordError');
            if (!newPasswordInput || !newPasswordError) return;
            
            const checklist = document.createElement('div');
            checklist.className = 'password-checklist';
            checklist.style.marginTop = '10px';
            checklist.style.fontSize = '12px';
            
            const requirements = [
                { id: 'length', text: 'At least 10 characters' },
                { id: 'uppercase', text: 'Uppercase letter (A-Z)' },
                { id: 'lowercase', text: 'Lowercase letter (a-z)' },
                { id: 'number', text: 'Number (0-9)' },
                { id: 'symbol', text: 'Symbol (!@#$ etc.)' }
            ];
            
            checklist.innerHTML = requirements.map(req => 
                `<div id="new-req-${req.id}" class="requirement-item" style="margin: 4px 0; color: #666;">
                    <span class="requirement-icon" style="margin-right: 6px;">✗</span> 
                    <span class="requirement-text">${req.text}</span>
                </div>`
            ).join('');
            
            newPasswordError.parentNode.insertBefore(checklist, newPasswordError.nextSibling);
            newPasswordInput.addEventListener('input', function() {
                const v = this.value;
                requirements.forEach(req => {
                    const el = document.getElementById('new-req-' + req.id);
                    if (!el) return;
                    let ok = false;
                    if (req.id === 'length') ok = v.length >= 10;
                    if (req.id === 'uppercase') ok = /[A-Z]/.test(v);
                    if (req.id === 'lowercase') ok = /[a-z]/.test(v);
                    if (req.id === 'number') ok = /[0-9]/.test(v);
                    if (req.id === 'symbol') ok = /[!@#$%^&*()\-_=+{};:,<.>]/.test(v);
                    const icon = el.querySelector('.requirement-icon');
                    icon.textContent = ok ? '✓' : '✗';
                    icon.style.color = ok ? '#4CAF50' : '#F44336';
                    el.style.color = ok ? '#4CAF50' : '#666';
                });
            });
        }
        initializePasswordValidation();

        // country select keyboard + click behavior (kept from original)
        (function setupCountrySelect(){
            const wrapper = document.getElementById('country-select');
            if (!wrapper) return;
            const btn = wrapper.querySelector('.select-btn');
            const opts = Array.from(wrapper.querySelectorAll('.options .opt'));
            const labelSpan = wrapper.querySelector('.label');
            const hiddenInput = document.getElementById('countryInput');

            function open(){ wrapper.classList.add('open'); wrapper.setAttribute('aria-expanded','true'); }
            function close(){ wrapper.classList.remove('open'); wrapper.setAttribute('aria-expanded','false'); }
            function toggle(){ wrapper.classList.toggle('open'); }

            if (btn) btn.addEventListener('click', function(e){ e.stopPropagation(); toggle(); });

            opts.forEach(o => {
                o.setAttribute('tabindex','0');
                o.addEventListener('click', function(){
                    const val = this.dataset.value || '';
                    const txt = this.textContent.trim();
                    const img = this.querySelector('img.flag');
                    if (labelSpan) labelSpan.innerHTML = (img ? img.outerHTML + ' ' : '') + txt;
                    if (hiddenInput) hiddenInput.value = val;
                    opts.forEach(x => x.setAttribute('aria-selected','false'));
                    this.setAttribute('aria-selected','true');
                    close();
                });
            });

            document.addEventListener('click', function(e){ if (!wrapper.contains(e.target)) close(); });

            wrapper.addEventListener('keydown', function(e){
                const KEY = e.key;
                if (KEY === 'Enter' || KEY === ' ') { e.preventDefault(); toggle(); return; }
                if (KEY === 'Escape') { close(); return; }
                if (KEY === 'ArrowDown' || KEY === 'ArrowUp') {
                    e.preventDefault();
                    if (!wrapper.classList.contains('open')) { open(); return; }
                    const focusable = opts;
                    let idx = focusable.indexOf(document.activeElement);
                    if (idx === -1) idx = 0;
                    idx = Math.max(0, Math.min(focusable.length - 1, idx + (KEY === 'ArrowDown' ? 1 : -1)));
                    focusable[idx].focus();
                }
            });
        })();

    })();
</script>

</main>

<script src="<?php echo $MAIN_ROOT; ?>assets/app.js" defer></script>

<?php
include("assets/_footer.php");
?>
