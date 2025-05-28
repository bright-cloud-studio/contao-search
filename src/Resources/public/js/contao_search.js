$( document ).ready(function() {

	var keywordsElement = $('div.mod_zyppy_search input[name="keywords"]');
	var action = window.location.href.split('?')[0];

	var request;

	if (keywordsElement.length > 0) {

		var keywordLoaded = keywordsElement.val();

		keywordsElement.each(function() {
			var wrapper = $(this).parents('div.mod_zyppy_search');
			if (wrapper.find('div.results').length < 1) {
				wrapper.append('<div class="results"></div>');
			}
		});

		keywordsElement.keyup(function(e) {
			e.preventDefault();
			var wrapper = $(this).parents('div.mod_zyppy_search');

			var search = wrapper.find('input[name="zyppy_search"]').val();

			var searchKeywords = $(this).val();
			//var action = $('div.mod_zyppy_search form').first().attr('action').split('?')[0];

			var queryType = wrapper.find('input[name="query_type"]:checked');
			if (queryType.length > 0) {
				searchData = { keywords: searchKeywords, IS_AJAX: "1", query_type: queryType.val(), zyppy_search: search };
			} else {
				searchData = { keywords: searchKeywords, IS_AJAX: "1", zyppy_search: search };
			}

			if (searchKeywords.length >= 1 && searchKeywords != "search the site" && action != '') {
				if (request) {
					request.abort();
				}
				request = $.get(action, searchData)
				.done(function( data ) {
					wrapper.find('div.results').empty();
					wrapper.find('div.results').append(data);
				});
			}
		});

		$('input[name="query_type"]').change(function(e) {
			var wrapper = $(this).parents('div.mod_zyppy_search');

			var search = wrapper.find('input[name="zyppy_search"]').val();

			var searchKeywords = wrapper.find('input[name="keywords"]').val();
			//var action = $('div.mod_zyppy_search form').first().attr('action').split('?')[0];

			var queryType = wrapper.find('input[name="query_type"]:checked');
			if (queryType.length > 0) {
				searchData = { keywords: searchKeywords, IS_AJAX: "1", query_type: queryType.val(), zyppy_search: search };
			} else {
				searchData = { keywords: searchKeywords, IS_AJAX: "1", zyppy_search: search };
			}

			if (searchKeywords.length >= 2 && searchKeywords != "search the site" && action != '') {
				if (request) {
					request.abort();
				}
				request = $.get(action, searchData)
				.done(function( data ) {
					wrapper.find('div.results').empty();
					wrapper.find('div.results').append(data);
				});
			}
		});
	}
});
