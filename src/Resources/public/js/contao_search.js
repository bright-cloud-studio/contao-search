$( document ).ready(function() {

	// Live search hits a dedicated lightweight endpoint that runs only the
	// search module, skipping the full-page render of the current URL.
	var action = '/_zyppy_search';

	// How many results to request per batch (must match / be <= the server cap).
	var BATCH = 20;

	// Distance (px) from the bottom at which the next batch is fetched.
	var LOAD_THRESHOLD = 400;

	// Status messages (override per-module via data-* attributes if desired).
	var MSG_LOADING = 'Loading…';
	var MSG_NO_MORE = 'No more results';
	var MSG_EMPTY   = 'No results found';

	$('div.mod_zyppy_search').each(function() {

		var wrapper = $(this);
		var keywordsElement = wrapper.find('input[name="keywords"]');

		if (keywordsElement.length < 1) {
			return;
		}

		// Ensure results div and spinner exist
		if (wrapper.find('div.results').length < 1) {
			wrapper.append('<div class="results"></div>');
		}
		var results = wrapper.find('div.results').first();
		if (wrapper.find('.zyppy_spinner').length < 1) {
			results.before('<div class="zyppy_spinner" aria-hidden="true"></div>');
		}
		var spinner = wrapper.find('.zyppy_spinner').first();

		// Status line for "Loading…", "No more results" and "No results found".
		if (wrapper.find('.zyppy_status').length < 1) {
			results.after('<div class="zyppy_status" aria-live="polite"></div>');
		}
		var status = wrapper.find('.zyppy_status').first();

		var search = wrapper.find('input[name="zyppy_search"]').val();
		// Module id: prefer an explicit hidden input, but fall back to the
		// existing "zyppy_search_<id>" value so no template change is needed.
		var moduleId = wrapper.find('input[name="zyppy_module_id"]').val();
		if (!moduleId && search) {
			moduleId = search.replace('zyppy_search_', '');
		}
		// Page id is optional — the server resolves the root by host if absent.
		var pageId = wrapper.find('input[name="zyppy_page_id"]').val() || '';

		// Per-module state so multiple search modules on a page stay independent.
		var state = {
			keywords:  '',
			queryType: '',
			offset:    0,
			done:      false,
			loading:   false,
			request:   null,
			debounce:  null
		};

		// Cache rendered batches by keywords + query type + offset so repeating
		// a search (or re-scrolling) is instant and skips the network.
		var cache = {};

		/** Count the top-level result blocks in a returned HTML fragment. */
		function countResults(html) {
			return $('<div>').html(html).children().length;
		}

		/** Set (or clear) the status line below the results. */
		function setStatus(text, modifier) {
			status.attr('class', 'zyppy_status' + (modifier ? ' ' + modifier : '')).text(text || '');
		}

		/** Is the bottom of the results list within the load threshold? */
		function nearBottom() {
			if (results.length < 1 || !results.is(':visible')) {
				return false;
			}

			var el = results[0];

			// Results in their own scroll container (e.g. the popup).
			if (el.scrollHeight > el.clientHeight + 1) {
				return el.scrollTop + el.clientHeight >= el.scrollHeight - LOAD_THRESHOLD;
			}

			// Otherwise use the viewport / page scroll.
			var rect = el.getBoundingClientRect();
			var viewH = window.innerHeight || document.documentElement.clientHeight;
			return rect.bottom - viewH <= LOAD_THRESHOLD;
		}

		function maybeLoadMore() {
			if (!state.done && !state.loading && state.keywords.length > 0 && nearBottom()) {
				loadBatch(true);
			}
		}

		/**
		 * Render a batch into the results container.
		 * @param data    HTML fragment from the server
		 * @param append  true to append (lazy load), false to replace (new search)
		 */
		function renderBatch(data, append) {
			var n = countResults(data);

			if (append) {
				results.append(data);
			} else {
				results.html(data).hide().fadeIn(150);
			}

			state.offset += n;

			if (n < BATCH) {
				state.done = true;
			}

			// Update the status line.
			if (state.done) {
				if (results.children().length < 1) {
					setStatus(MSG_EMPTY, 'is-empty');
				} else {
					setStatus(MSG_NO_MORE, 'is-done');
				}
			} else {
				setStatus('');
			}

			// A short list might not fill the viewport — keep loading until it
			// does (or we run out), so the scroll handler has something to act on.
			maybeLoadMore();
		}

		/**
		 * Fetch a batch starting at the current offset.
		 * @param append  true for lazy-load append, false for a fresh search
		 */
		function loadBatch(append) {
			if (state.loading || (append && state.done)) {
				return;
			}

			var cacheKey = state.keywords + '||' + state.queryType + '||' + state.offset;

			// Served from cache — no network round-trip needed.
			if (cache[cacheKey] !== undefined) {
				spinner.removeClass('is-active');
				renderBatch(cache[cacheKey], append);
				return;
			}

			state.loading = true;
			spinner.addClass('is-active');

			// Show an inline "Loading…" hint at the bottom while appending.
			if (append) {
				setStatus(MSG_LOADING, 'is-loading');
			}

			if (state.request) {
				state.request.abort();
			}

			var searchData = {
				keywords:     state.keywords,
				IS_AJAX:      '1',
				zyppy_search: search,
				id:           moduleId,
				page:         pageId,
				zyppy_offset: state.offset,
				zyppy_limit:  BATCH
			};
			if (state.queryType) {
				searchData.query_type = state.queryType;
			}

			state.request = $.get(action, searchData)
				.done(function(data) {
					cache[cacheKey] = data;
					spinner.removeClass('is-active');
					state.loading = false;
					renderBatch(data, append);
				})
				.fail(function(xhr) {
					state.loading = false;
					// Only hide the spinner on a real error, not an intentional abort
					if (xhr.statusText !== 'abort') {
						spinner.removeClass('is-active');
						setStatus('');
					}
				});
		}

		/** Start a fresh search from the first batch. */
		function newSearch() {
			state.offset = 0;
			state.done = false;
			results.empty();
			setStatus('');
			loadBatch(false);
		}

		keywordsElement.keyup(function(e) {
			e.preventDefault();

			var searchKeywords = $(this).val();

			// Cancel any pending debounce from a previous keypress
			clearTimeout(state.debounce);

			// Empty field — cancel request, hide spinner, clear results
			if (searchKeywords.length < 1 || searchKeywords === 'search the site') {
				if (state.request) { state.request.abort(); }
				spinner.removeClass('is-active');
				results.empty();
				setStatus('');
				state.keywords = '';
				state.offset = 0;
				state.done = false;
				return;
			}

			// Show spinner immediately — user knows something is in progress
			spinner.addClass('is-active');

			state.keywords = searchKeywords;
			state.queryType = wrapper.find('input[name="query_type"]:checked').val() || '';

			// Wait 300 ms after the last keypress before sending the request
			state.debounce = setTimeout(newSearch, 300);
		});

		wrapper.find('input[name="query_type"]').change(function(e) {
			var searchKeywords = keywordsElement.val();

			if (searchKeywords.length < 2 || searchKeywords === 'search the site') {
				return;
			}

			state.keywords = searchKeywords;
			state.queryType = wrapper.find('input[name="query_type"]:checked').val() || '';
			spinner.addClass('is-active');
			newSearch();
		});

		// Lazy-load triggers: page scroll, the results container's own scroll,
		// and window resize (a wider viewport may reveal the threshold).
		$(window).on('scroll resize', maybeLoadMore);
		results.on('scroll', maybeLoadMore);
	});
});
