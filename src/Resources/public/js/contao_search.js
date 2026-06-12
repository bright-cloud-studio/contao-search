$( document ).ready(function() {

	var keywordsElement = $('div.mod_zyppy_search input[name="keywords"]');
	var action = window.location.href.split('?')[0];

	var request;
	var debounceTimer;
	var queryCache = {};

	if (keywordsElement.length > 0) {

		// Ensure results div and spinner exist inside each search module
		keywordsElement.each(function() {
			var wrapper = $(this).parents('div.mod_zyppy_search');
			if (wrapper.find('div.results').length < 1) {
				wrapper.append('<div class="results"></div>');
			}
			if (wrapper.find('.zyppy_spinner').length < 1) {
				wrapper.find('div.results').before('<div class="zyppy_spinner" aria-hidden="true"></div>');
			}
		});

		/**
		 * Fire the AJAX request. Called after the debounce delay expires.
		 * Serves from the in-session cache when the same query was already fetched.
		 */
		function doSearch(wrapper, searchKeywords, queryType) {
			var search = wrapper.find('input[name="zyppy_search"]').val();
			var cacheKey = searchKeywords + '||' + (queryType || '');

			// Instant result from cache — no network round-trip needed
			if (queryCache[cacheKey] !== undefined) {
				wrapper.find('.zyppy_spinner').removeClass('is-active');
				wrapper.find('div.results').html(queryCache[cacheKey]);
				return;
			}

			var searchData = {
				keywords:    searchKeywords,
				IS_AJAX:     '1',
				zyppy_search: search
			};
			if (queryType) {
				searchData.query_type = queryType;
			}

			if (request) {
				request.abort();
			}

			request = $.get(action, searchData)
				.done(function(data) {
					queryCache[cacheKey] = data;
					wrapper.find('.zyppy_spinner').removeClass('is-active');
					wrapper.find('div.results').html(data).hide().fadeIn(150);
				})
				.fail(function(xhr) {
					// Only hide the spinner on a real error, not an intentional abort
					if (xhr.statusText !== 'abort') {
						wrapper.find('.zyppy_spinner').removeClass('is-active');
					}
				});
		}

		keywordsElement.keyup(function(e) {
			e.preventDefault();
			var wrapper       = $(this).parents('div.mod_zyppy_search');
			var searchKeywords = $(this).val();

			// Cancel any pending debounce from a previous keypress
			clearTimeout(debounceTimer);

			// Empty field — cancel request, hide spinner, clear results
			if (searchKeywords.length < 1 || searchKeywords === 'search the site') {
				if (request) { request.abort(); }
				wrapper.find('.zyppy_spinner').removeClass('is-active');
				wrapper.find('div.results').empty();
				return;
			}

			// Show spinner immediately — user knows something is in progress
			wrapper.find('.zyppy_spinner').addClass('is-active');

			var queryType = wrapper.find('input[name="query_type"]:checked').val();

			// Wait 300 ms after the last keypress before sending the request
			debounceTimer = setTimeout(function() {
				doSearch(wrapper, searchKeywords, queryType);
			}, 300);
		});

		$('input[name="query_type"]').change(function(e) {
			var wrapper        = $(this).parents('div.mod_zyppy_search');
			var searchKeywords = wrapper.find('input[name="keywords"]').val();

			if (searchKeywords.length < 2 || searchKeywords === 'search the site' || action === '') {
				return;
			}

			var queryType = wrapper.find('input[name="query_type"]:checked').val();
			wrapper.find('.zyppy_spinner').addClass('is-active');
			doSearch(wrapper, searchKeywords, queryType);
		});
	}
});
