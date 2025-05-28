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
 * Palettes
 */
foreach($GLOBALS['TL_DCA']['tl_page']['palettes'] as $name => $palette) {
	if (is_string($palette)) {
		if ($strSub = stristr($palette, '{meta_legend}')) {
			$strSub2 = stristr($strSub, ';', TRUE);
			if ($strSub2 !== FALSE) {
				$GLOBALS['TL_DCA']['tl_page']['palettes'][$name] = str_replace($strSub2, $strSub2 .',zyppy_news', $GLOBALS['TL_DCA']['tl_page']['palettes'][$name]);
			} else {
				$GLOBALS['TL_DCA']['tl_page']['palettes'][$name] = str_replace($strSub, $strSub .',zyppy_news', $GLOBALS['TL_DCA']['tl_page']['palettes'][$name]);
			}
		}
	}

}


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['zyppy_news'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_page']['zyppy_news'],
	'exclude'                 => true,
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12'),
	'sql'                     => "char(1) NOT NULL default ''"
);
