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
$GLOBALS['TL_DCA']['tl_module']['palettes']['zyppy_search'] = '{title_legend},name,headline,type;{config_legend},queryType,fuzzy,contextLength,minKeywordLength,totalLength,perPage,searchType,disableAjax,formatPageTeaser,formatPageDescription,formatNewsTeaser;{redirect_legend:hide},jumpTo;{reference_legend:hide},pages;{template_legend:hide},searchTpl,ajaxTpl,customTpl;{protected_legend:hide},protected;{image_legend},imgSize;{expert_legend:hide},guests,cssID';


/**
 * Sub Palettes
 */
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatPageTeaser';
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatPageDescription';
$GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__'][] = 'formatNewsTeaser';

$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageTeaser'] = 'pageTeaserLimit';
$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageDescription'] = 'pageDescriptionLimit';
$GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatNewsTeaser'] = 'newsTeaserLimit';


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_module']['fields']['ajaxTpl'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['ajaxTpl'],
	'inputType'               => 'select',
	'options_callback' => static function ()
	{
		return \Contao\Controller::getTemplateGroup('search_');
	},
	'eval'                    => array('tl_class'=>'w50'),
	'sql'                     => "varchar(64) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['disableAjax'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['disableAjax'],
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12'),
	'sql'                     => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['formatPageTeaser'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['formatPageTeaser'],
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12', 'submitOnChange'=>true),
	'sql'                     => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['pageTeaserLimit'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['pageTeaserLimit'],
	'inputType'               => 'text',
	'eval'                    => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'                     => "int(10) unsigned NOT NULL default 0"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['formatPageDescription'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['formatPageDescription'],
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12', 'submitOnChange'=>true),
	'sql'                     => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['pageDescriptionLimit'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['pageDescriptionLimit'],
	'inputType'               => 'text',
	'eval'                    => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'                     => "int(10) unsigned NOT NULL default 0"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['formatNewsTeaser'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['formatNewsTeaser'],
	'inputType'               => 'checkbox',
	'eval'                    => array('tl_class'=>'clr w50 m12','submitOnChange'=>true),
	'sql'                     => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_module']['fields']['newsTeaserLimit'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['tl_module']['newsTeaserLimit'],
	'inputType'               => 'text',
	'eval'                    => array('maxlength'=>10, 'rgxp'=>'natural', 'tl_class'=>'w50'),
	'sql'                     => "int(10) unsigned NOT NULL default 0"
);
