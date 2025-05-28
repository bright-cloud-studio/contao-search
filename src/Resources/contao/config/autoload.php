<?php

/**
 * Zyppy Search
 *
 * Copyright (C) 2019-2022 Andrew Stevens Consulting
 *
 * @package    asconsulting/zyppy_search
 * @link       https://andrewstevens.consulting
 */


/**
 * Register the classes
 */
ClassLoader::addClasses(array
(
    'ZyppySearch\Module\ZyppySearch' 	=> 'system/modules/zyppy_search/library/ZyppySearch/Module/ZyppySearch.php'
));


/**
 * Register the templates
 */
TemplateLoader::addFiles(array
(
	'mod_search_zyppy' 		=> 'system/modules/zyppy_search/templates/modules',
	'search_zyppy' 			=> 'system/modules/zyppy_search/templates/search',
));
