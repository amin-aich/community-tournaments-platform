// assets/app.js  -- aggressive interceptor + debug (scroll-to-top + push friendly URL)
// assets/app.js  -- SPA router (patched: correct scroll-to-top & focus behavior)
(function () {
	const container = document.getElementById('app-root') || document.getElementById('container') || document.querySelector('main') || document.body;
	const siteBase = (window.SITE && window.SITE.base) ? window.SITE.base : '/';

	// add this to disable browser auto scroll restoration
	try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}

	// console.log('[SPA router] init. container=', container && container.id ? container.id : '(body)', ' siteBase=', siteBase);

	function insertHTML(html) {
		const target = container || document.body;
		target.innerHTML = html;
		// run inline scripts
		const scripts = Array.from(target.querySelectorAll('script'));
		scripts.forEach(oldScript => {
			const script = document.createElement('script');
			for (let i = 0; i < oldScript.attributes.length; i++) {
				const attr = oldScript.attributes[i];
				script.setAttribute(attr.name, attr.value);
			}
			script.text = oldScript.textContent;
			oldScript.parentNode.replaceChild(script, oldScript);
		});
	}

	// ---------- FOCUS & SCROLL HELPERS (replaces old focusMain & scrollToTop) ----------
	function getHeaderOffset() {
		// try common header selectors; fallback to detecting any top fixed element
		try {
			const headerSelectors = ['header', '.site-header', '#site-header', '.fixed-header', '.topbar'];
			for (const sel of headerSelectors) {
				const el = document.querySelector(sel);
				if (el) {
					const st = window.getComputedStyle(el);
					if (st.position === 'fixed' || st.position === 'sticky') {
						return Math.max(0, Math.round(el.getBoundingClientRect().height));
					}
				}
			}
			// fallback: check direct children that are fixed/sticky near top
			const children = Array.from(document.body.children);
			for (const el of children) {
				const st = window.getComputedStyle(el);
				const rect = el.getBoundingClientRect();
				if ((st.position === 'fixed' || st.position === 'sticky') && rect.bottom <= 200 && rect.height > 0) {
					return Math.max(0, Math.round(rect.height));
				}
			}
		} catch (e) {
			// ignore
		}
		return 0;
	}

	// Wait for layout to settle (two RAFs), then scroll to top + header offset.
	// Pass 'smooth' or 'auto' for behavior.
	function scrollToTopAfterRender(behavior = 'auto') {
		const offset = getHeaderOffset();
		requestAnimationFrame(() => {
			requestAnimationFrame(() => {
				const top = Math.max(0, offset);
				try {
					window.scrollTo({ top: top, left: 0, behavior });
				} catch (e) {
					window.scrollTo(0, top);
				}
			});
		});
	}

	function focusMain() {
		try {
			const root = container || document.body;
			root.setAttribute('tabindex', '-1');
			// use preventScroll so focus won't move the viewport
			if (typeof root.focus === 'function') root.focus({ preventScroll: true });
		} catch (e) {}
		const h = (container || document.body).querySelector('h1, h2, h3, [role="main"]');
		if (h) {
			try {
				h.setAttribute('tabindex', '-1');
				h.focus({ preventScroll: true });
			} catch (e) {
				try { h.focus(); } catch (e2) {}
			}
		}
	}
	
	// -------------------------- active-link & mobile icon helper --------------------------
	function normalizePath(path) {
		try {
			const url = new URL(path, location.origin);
			// remove trailing slashes (but keep root '/')
			let p = url.pathname.replace(/\/+$/, '');
			if (p === '') p = '/';
			// include search if you want to match querystring too (optional)
			return p + (url.search || '');
		} catch (e) {
			// fallback: ensure starts with /
			if (!path) return '/';
			if (!path.startsWith('/')) path = '/' + path;
			return path.replace(/\/+$/, '') || '/';
		}
	}

	// robust active-link updater (replace your previous updateActiveLinks with this)
	function updateActiveLinks() {
		try {
			// debug start
			console.group && console.group('[SPA] updateActiveLinks');

			// current friendly path (normalize)
			const currentFull = (location.pathname || '') + (location.search || '');
			const current = currentFull.replace(/\/+$/, '') || '/';
			// console.log('[SPA] current path =', current);

			// collect anchors in desktop + mobile areas
			const anchors = Array.from(document.querySelectorAll('.headerD .headerItemsDiv a, .headerDivM .headerItemsDivM a'));

			let matched = 0;
			anchors.forEach(a => {
				// use a.href (absolute) to avoid relative quirks
				const abs = a.href || a.getAttribute('href') || '';
				// normalize anchor path
				let anchorPath;
				try {
					anchorPath = (new URL(abs, location.origin)).pathname + (new URL(abs, location.origin)).search;
					anchorPath = anchorPath.replace(/\/+$/, '') || '/';
				} catch (e) {
					anchorPath = (abs || '').replace(/\/+$/, '') || '/';
				}

				// match rules: exact OR current endsWith anchorPath OR anchorPath endsWith current
				const isMatch = (anchorPath === current) || (current.endsWith(anchorPath) && anchorPath !== '/') || (anchorPath.endsWith(current) && current !== '/');

				// remove any previous class (safe)
				a.classList.remove('link_active');

				// find images inside this anchor
				const imgs = Array.from(a.querySelectorAll('img'));
				const imgsWithData = imgs.filter(i => i.dataset && (i.dataset.activeSrc || i.dataset.inactiveSrc));

				if (isMatch) {
					a.classList.add('link_active');
					matched++;
					// If there are imgs with data-attrs, swap them and hide plain duplicates
					if (imgsWithData.length > 0) {
						imgsWithData.forEach(img => {
							if (img.dataset.activeSrc) img.src = img.dataset.activeSrc;
							// ensure visible
							img.style.display = '';
						});
						// hide any imgs that don't have data attributes (duplicates)
						imgs.filter(i => !(i.dataset && (i.dataset.activeSrc || i.dataset.inactiveSrc))).forEach(i => i.style.display = 'none');
					} else {
						// no data-attrs: keep images as-is (or apply a CSS class)
						imgs.forEach(i => i.style.display = '');
					}
				} else {
					// not active: restore inactive images and make plain imgs visible again
					if (imgsWithData.length > 0) {
						imgsWithData.forEach(img => {
							if (img.dataset.inactiveSrc) img.src = img.dataset.inactiveSrc;
							// ensure visible
							img.style.display = '';
						});
						// ensure plain images (duplicates) are visible when not active
						imgs.filter(i => !(i.dataset && (i.dataset.activeSrc || i.dataset.inactiveSrc))).forEach(i => i.style.display = '');
					} else {
						imgs.forEach(i => i.style.display = '');
					}
				}

				// debug each anchor
				// console.log('[SPA] anchor', anchorPath, 'match=', isMatch, '->', a);
			});

			//console.log('[SPA] matched anchors =', matched);
			console.groupEnd && console.groupEnd();
		} catch (e) {
			console.error('[SPA] updateActiveLinks failed', e);
		}
	}

	// --------------------------------------------------------------------------------------

	function pathToFragment(path) {
		try {
			const url = new URL(path, window.location.origin);
			const pathname = url.pathname + url.search;

			if (pathname.startsWith('/fragment/')) return pathname + (url.hash || '');

			// Insert /fragment/ after the base path, not at the very beginning
			const segments = pathname.split('/').filter(seg => seg);
			if (segments.length > 0) {
				segments.splice(1, 0, 'fragment'); // Insert 'fragment' after the first segment
				return '/' + segments.join('/') + (url.hash || '');
			}
			return '/fragment' + pathname + (url.hash || '');
		} catch (e) {
			// fallback - handle manually
			if (path.startsWith('/fragment/')) return path;
			if (!path.startsWith('/')) path = '/' + path;

			const segments = path.split('/').filter(seg => seg);
			if (segments.length > 0) {
				segments.splice(1, 0, 'fragment');
				return '/' + segments.join('/');
			}
			return '/fragment' + path;
		}
	}

	async function fetchFragment(path, push = true) {
		// console.log('[SPA router] fetchFragment ->', path);

		// NOTE: we NO LONGER scroll here BEFORE content is inserted.
		// Scrolling will happen AFTER insertHTML() and focusMain() via scrollToTopAfterRender()

		try {
			const resp = await fetch(path, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json, text/html' }
			});
			if (!resp.ok) throw new Error('Bad response ' + resp.status);
			const ct = resp.headers.get('content-type') || '';
			if (ct.includes('application/json')) {
				const json = await resp.json();
				if (json.meta) {
					if (json.meta.title) document.title = json.meta.title;
				}
				if (json.html) insertHTML(json.html);
				if (push) {
					// push friendly URL (remove /fragment prefix)
					const friendly = path.replace(/(^|\/)fragment\//, '$1') || '/';
					history.pushState({ url: path }, '', friendly);
				}
			} else {
				const html = await resp.text();
				insertHTML(html);
				// update title if fragment root has data-title
				try {
					const root = (container || document.body).querySelector('[data-title]');
					if (root && root.getAttribute('data-title')) document.title = root.getAttribute('data-title');
				} catch (e) {}
				if (push) {
					// push friendly URL (remove /fragment prefix)
					const friendly = path.replace(/(^|\/)fragment\//, '$1') || '/';
					history.pushState({ url: friendly }, '', friendly);
				}
			}

			// focus without scrolling
			focusMain();
			
			// after focusMain() and after history.pushState
			updateActiveLinks();

			// Force scroll to absolute top of page AND clear common scroll targets.
			// Run immediately and again a bit later to beat any late layout shifts.
			(function forceTop() {
				try {
					// immediate attempts
					window.scrollTo(0, 0);
					document.documentElement.scrollTop = 0;
					document.body.scrollTop = 0;
					if (container && ('scrollTop' in container)) container.scrollTop = 0;
				} catch (e) {}

				// a tiny async retry to handle reflows/images/focus-induced jumps
				setTimeout(() => {
					try {
						window.scrollTo(0, 0);
						document.documentElement.scrollTop = 0;
						document.body.scrollTop = 0;
						if (container && ('scrollTop' in container)) container.scrollTop = 0;
					} catch (e) {}
				}, 50);

				// final safety retry after more layout work
				setTimeout(() => {
					try {
						window.scrollTo(0, 0);
						document.documentElement.scrollTop = 0;
						document.body.scrollTop = 0;
						if (container && ('scrollTop' in container)) container.scrollTop = 0;
						// debug log - paste this output if it still misbehaves
						// console.log('[SPA] scroll positions -> window.scrollY=', window.scrollY,
									// 'docEl=', document.documentElement.scrollTop,
									// 'body=', document.body.scrollTop,
									// 'container=', container && container.scrollTop);
					} catch (e) {}
				}, 250);
			})();

			if (window.gtag) window.gtag('event', 'page_view', { page_path: location.pathname });
		} catch (err) {
			console.error('[SPA router] fetch failed', err);
			// fallback to full friendly URL
			const friendly = path.replace(/^\/fragment/, '') || '/';
			window.location.href = friendly;
		}
	}

	// Decides whether to intercept a link
	function shouldHandleLink(a) {
		if (!a) return false;

		// ignore if explicitly opted out
		if (a.hasAttribute('data-no-async')) return false;

		// ignore if has target other than _self
		if (a.target && a.target !== '' && a.target !== '_self') return false;

		// ignore downloads or file anchors/mailto/tel/javascript
		const href = a.getAttribute('href') || '';
		if (!href) return false;
		if (href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return false;
		if (a.hasAttribute('download')) return false;

		// ONLY allow explicit opt-in (remove the automatic .php interception)
		if (a.hasAttribute('data-async')) return true;

		return false; // Don't intercept anything else
	}

	// Click handler (delegated)
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented) return;
		// only left click
		if (e.button && e.button !== 0) return;
		if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

		const a = e.target.closest('a');
		if (!a) return; // no anchor found

		// debug log
		// console.log('[SPA router] clicked anchor ->', a.href, 'data-async=', a.getAttribute('data-async'));

		// decide
		if (!shouldHandleLink(a)) {
			// console.log('[SPA router] allowed default navigation for', a.href);
			return; // let browser handle it
		}

		// intercept
		e.preventDefault();
		e.stopPropagation();

		// compute fragment path and fetch
		const frag = pathToFragment(a.getAttribute('href'));
		// console.log('[SPA router] intercepting and fetching fragment', frag);
		fetchFragment(frag, true);
	}, { capture: false });

	// handle popstate
	window.addEventListener('popstate', (e) => {
		let url = (e.state && e.state.url) ? e.state.url : '/fragment' + location.pathname;
		if (!url.startsWith('/fragment')) url = '/fragment' + location.pathname;
		fetchFragment(url, false);
	});

	// console.log('[SPA router] ready — will intercept internal links (see logs when clicking).');
	
	// run once on load to mark the correct active link
	updateActiveLinks();

	// expose fetchFragment for inline/early handlers
	window.fetchFragment = fetchFragment;

})();