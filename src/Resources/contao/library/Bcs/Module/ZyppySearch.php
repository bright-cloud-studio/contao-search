<?php

/**
 * Zyppy Search
 *
 * Copyright (C) 2019-2022 Andrew Stevens Consulting
 *
 * @package    asconsulting/zyppy_search
 * @link       https://andrewstevens.consulting
 */



namespace ZyppySearch\Module;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Security\ContaoCorePermissions;

use Contao\BackendTemplate;
use Contao\Config;
use Contao\Environment;
use Contao\FilesModel;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\ModuleSearch;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\Pagination;
use Contao\StringUtil;
use Contao\Search;
use Contao\System;



/**
 * Front end module "search".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ZyppySearch extends ModuleSearch
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_search_zyppy';

	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		$request = System::getContainer()->get('request_stack')->getCurrentRequest();

		if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
		{
			$objTemplate = new BackendTemplate('be_wildcard');
			$objTemplate->wildcard = '### ' . $GLOBALS['TL_LANG']['FMD']['search'][0] . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->pages = StringUtil::deserialize($this->pages);

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{

		$this->Template->class .= ' zyppy_search_' .$this->id;

		if (!in_array('bundles/bcssearch/js/contao_search.js', $GLOBALS['TL_JAVASCRIPT'])) {
			$GLOBALS['TL_JAVASCRIPT'][] = 'bundles/bcssearch/js/contao_search.js';
		}

		// Mark the x and y parameter as used (see #4277)
		if (isset($_GET['x']))
		{
			Input::get('x');
			Input::get('y');
		}

		// Trigger the search module from a custom form
		if (!isset($_GET['keywords']) && Input::post('FORM_SUBMIT') == 'tl_search')
		{
			$_GET['keywords'] = Input::post('keywords');
			$_GET['query_type'] = Input::post('query_type');
			$_GET['per_page'] = Input::post('per_page');
		}

		$blnFuzzy = $this->fuzzy;
		$strQueryType = Input::get('query_type') ?: $this->queryType;
		$strKeywords = trim(Input::get('keywords'));

		$this->Template->uniqueId = $this->id;
		$this->Template->queryType = $strQueryType;
		$this->Template->keyword = StringUtil::specialchars($strKeywords);
		$this->Template->keywordLabel = $GLOBALS['TL_LANG']['MSC']['keywords'];
		$this->Template->optionsLabel = $GLOBALS['TL_LANG']['MSC']['options'];
		$this->Template->search = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['searchLabel']);
		$this->Template->matchAll = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['matchAll']);
		$this->Template->matchAny = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['matchAny']);
		$this->Template->action = StringUtil::ampersand(Environment::get('indexFreeRequest'));
		$this->Template->advanced = ($this->searchType == 'advanced');

		// Redirect page
		if (($objTarget = $this->objModel->getRelated('jumpTo')) instanceof PageModel)
		{
			/** @var PageModel $objTarget */
			$this->Template->action = $objTarget->getFrontendUrl();
		}

		$this->Template->pagination = '';
		$this->Template->results = '';

		$boolAjax = (Input::get('IS_AJAX') ? true : false);

		// Execute the search if there are keywords
		if ($strKeywords !== '' && $strKeywords != '*')
		{
			// Search pages
			if (!empty($this->pages) && \is_array($this->pages))
			{
				$varRootId = implode('-', $this->pages);
				$arrPages = array();

				foreach ($this->pages as $intPageId)
				{
					$arrPages[] = array($intPageId);
					$arrPages[] = $this->Database->getChildRecords($intPageId, 'tl_page');
				}

				if (!empty($arrPages))
				{
					$arrPages = array_merge(...$arrPages);
				}

				$arrPages = array_unique($arrPages);
			}
			// Website root
			else
			{
				/** @var PageModel $objPage */
				global $objPage;

				$varRootId = $objPage->rootId;
				$arrPages = $this->Database->getChildRecords($objPage->rootId, 'tl_page');
			}

			// HOOK: add custom logic (see #5223)
			if (isset($GLOBALS['TL_HOOKS']['customizeSearch']) && \is_array($GLOBALS['TL_HOOKS']['customizeSearch']))
			{
				foreach ($GLOBALS['TL_HOOKS']['customizeSearch'] as $callback)
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($arrPages, $strKeywords, $strQueryType, $blnFuzzy, $this);
				}
			}

			// Return if there are no pages
			if (empty($arrPages) || !\is_array($arrPages))
			{
				return;
			}

			$query_starttime = microtime(true);

			try
			{
				$objResult = Search::query($strKeywords, ($strQueryType == 'or'), $arrPages, $blnFuzzy, $this->minKeywordLength);
			}
			catch (\Exception $e)
			{
				System::getContainer()->get('monolog.logger.contao.error')->error('Website search failed: ' . $e->getMessage());

				$objResult = new SearchResult(array());
			}

			$query_endtime = microtime(true);

			// Sort out protected pages
			if (Config::get('indexProtected'))
			{
				$objResult->applyFilter(static function ($v)
				{
					return empty($v['protected']) || System::getContainer()->get('security.helper')->isGranted(ContaoCorePermissions::MEMBER_IN_GROUPS, StringUtil::deserialize($v['groups'] ?? null, true));
				});
			}

			$count = $objResult->getCount();

			$this->Template->count = $count;
			$this->Template->page = null;
			$this->Template->keywords = $strKeywords;

			if ($this->minKeywordLength > 0)
			{
				$this->Template->keywordHint = sprintf($GLOBALS['TL_LANG']['MSC']['sKeywordHint'], $this->minKeywordLength);
			}

			// No results
			if ($count < 1)
			{
				if ($boolAjax) {
					exit();
				}

				$this->Template->header = sprintf($GLOBALS['TL_LANG']['MSC']['sEmpty'], $strKeywords);
				$this->Template->duration = System::getFormattedNumber($query_endtime - $query_starttime, 3) . ' ' . $GLOBALS['TL_LANG']['MSC']['seconds'];

				return;
			}

			$from = 1;
			$to = $count;

			// Pagination
			if ($this->perPage > 0)
			{
				$id = 'page_s' . $this->id;
				$page = Input::get($id) ?? 1;
				$per_page = Input::get('per_page') ?: $this->perPage;

				// Do not index or cache the page if the page number is outside the range
				if ($page < 1 || $page > max(ceil($count/$per_page), 1))
				{
					throw new PageNotFoundException('Page not found: ' . Environment::get('uri'));
				}

				$from = (($page - 1) * $per_page) + 1;
				$to = (($from + $per_page) > $count) ? $count : ($from + $per_page - 1);

				// Pagination menu
				if ($to < $count || $from > 1)
				{
					$objPagination = new Pagination($count, $per_page, Config::get('maxPaginationLinks'), $id);
					$this->Template->pagination = $objPagination->generate("\n  ");
				}

				$this->Template->page = $page;
			}

			$contextLength = 48;
			$totalLength = 360;

			$lengths = StringUtil::deserialize($this->contextLength, true) + array(null, null);

			if ($lengths[0] > 0)
			{
				$contextLength = $lengths[0];
			}

			if ($lengths[1] > 0)
			{
				$totalLength = $lengths[1];
			}

			$arrResult = $objResult->getResults($to-$from+1, $from-1);

			// Get the results
			foreach (array_keys($arrResult) as $i)
			{

				$objResultPage = PageModel::findByPk($arrResult[$i]['pid']);

				$strTemplate = ($boolAjax ? $this->ajaxTpl : $this->searchTpl);

				$objTemplate = new FrontendTemplate($strTemplate ? $strTemplate : 'search_default');
				$objTemplate->setData($arrResult[$i]);
				$objTemplate->href = $arrResult[$i]['url'];
				$objTemplate->link = $arrResult[$i]['title'];
				$objTemplate->url = StringUtil::specialchars(urldecode($arrResult[$i]['url']), true, true);
				$objTemplate->title = StringUtil::specialchars(StringUtil::stripInsertTags(($arrResult[$i]['title'] ?? '')));
				$objTemplate->class = ($i == 0 ? 'first ' : '') . ((empty($arrResult[$i+1])) ? 'last ' : '') . (($i % 2 == 0) ? 'even' : 'odd');
				$objTemplate->relevance = sprintf($GLOBALS['TL_LANG']['MSC']['relevance'], number_format($arrResult[$i]['relevance'] / $arrResult[0]['relevance'] * 100, 2) . '%');
				$objTemplate->unit = $GLOBALS['TL_LANG']['UNITS'][1];


				if ($objResultPage) {
					if ($objResultPage->zyppy_news) {
						$strNewsAlias = basename($arrResult[$i]['url'], '.html');
						$objNewsModel = NewsModel::findBy('alias', $strNewsAlias);

						if ($objNewsModel) {
							$objTemplate->isNews = 1;
							if ($objNewsModel->addImage && $objNewsModel->singleSRC) {
								$strPhoto = '';
								$uuid = StringUtil::binToUuid($objNewsModel->singleSRC);
								$objFile = FilesModel::findByUuid($uuid);
								$objTemplate->newsImage = $objFile->path;
							}
							if ($this->formatNewsTeaser) {
								$objTemplate->newsTeaser = $this->formatText($objNewsModel->teaser, $this->newsTeaserLimit);
							} else {
								$objTemplate->newsTeaser = $objNewsModel->teaser;
							}
						}
					}
					$objTemplate->isPage = 1;
					if ($objResultPage->page_image) {
						$strPhoto = '';
						$uuid = StringUtil::binToUuid($objResultPage->page_image);
						$objFile = FilesModel::findByUuid($uuid);
						$objTemplate->pageImage = $objFile->path;
					}

					if ($this->formatPageTeaser) {
						$objTemplate->pageTeaser = $this->formatText($objResultPage->page_teaser, $this->pageTeaserLimit);
					} else {
						$objTemplate->pageTeaser = $objResultPage->page_teaser;
					}

					if ($this->formatPageDescription) {
						$objTemplate->pageDescription = $this->formatText($objResultPage->description, $this->pageDescriptionLimit);
					} else {
						$objTemplate->pageDescription = $objResultPage->description;
					}

					if (\Input::get('debug')) {
						echo "Format News Teaser: " .$this->formatNewsTeaser ."<br>";
						echo "News Teaser Limit: " .$this->newsTeaserLimit ."<br>";
						echo "Format Page Teaser: " .$this->formatPageTeaser ."<br>";
						echo "Page Teaser Limit: " .$this->pageTeaserLimit ."<br>";
						echo "Format Page Description: " .$this->formatPageDescription ."<br>";
						echo "Page Description Limit: " .$this->pageDescriptionLimit ."<br>";
						die();
					}

				}

				$arrContext = array();
				$strText = StringUtil::stripInsertTags(($arrResult[$i]['text'] ?? ''));
				$arrMatches = Search::getMatchVariants(StringUtil::trimsplit(',', $arrResult[$i]['matches']), $strText, $GLOBALS['TL_LANGUAGE']);

				// Get the context
				foreach ($arrMatches as $strWord)
				{
					$arrChunks = array();
					preg_match_all('/(^|\b.{0,' . $contextLength . '}(?:\PL|\p{Hiragana}|\p{Katakana}|\p{Han}|\p{Myanmar}|\p{Khmer}|\p{Lao}|\p{Thai}|\p{Tibetan}))' . preg_quote($strWord, '/') . '((?:\PL|\p{Hiragana}|\p{Katakana}|\p{Han}|\p{Myanmar}|\p{Khmer}|\p{Lao}|\p{Thai}|\p{Tibetan}).{0,' . $contextLength . '}\b|$)/ui', $strText, $arrChunks);

					foreach ($arrChunks[0] as $strContext)
					{
						$arrContext[] = ' ' . $strContext . ' ';
					}

					// Skip other terms if the total length is already reached
					if (array_sum(array_map('mb_strlen', $arrContext)) >= $totalLength)
					{
						break;
					}
				}

				// Shorten the context and highlight all keywords
				if (!empty($arrContext))
				{
					$objTemplate->context = trim(StringUtil::substrHtml(implode('…', $arrContext), $totalLength));
					$objTemplate->context = preg_replace('((?<=^|\PL|\p{Hiragana}|\p{Katakana}|\p{Han}|\p{Myanmar}|\p{Khmer}|\p{Lao}|\p{Thai}|\p{Tibetan})(' . implode('|', array_map('preg_quote', $arrMatches)) . ')(?=\PL|\p{Hiragana}|\p{Katakana}|\p{Han}|\p{Myanmar}|\p{Khmer}|\p{Lao}|\p{Thai}|\p{Tibetan}|$))ui', '<mark class="highlight">$1</mark>', $objTemplate->context);

					$objTemplate->hasContext = true;
				}

				$this->addImageToTemplateFromSearchResult($arrResult[$i], $objTemplate);

				$this->Template->results .= $objTemplate->parse();
			}

			$this->Template->header = vsprintf($GLOBALS['TL_LANG']['MSC']['sResults'], array($from, $to, $count, $strKeywords));
			$this->Template->duration = System::getFormattedNumber($query_endtime - $query_starttime, 3) . ' ' . $GLOBALS['TL_LANG']['MSC']['seconds'];

			if ($boolAjax && Input::get('zyppy_search') == 'zyppy_search_' .$this->id) {
				echo $this->Template->results;
				exit();
			}
		}
	}

	protected function formatText($strTextRaw, $intLength = 100) {
		$strText = strip_tags($strTextRaw);
		$arrTextChunks = preg_split('/\b/', $strText);
		if ($intLength > 0) {
			$strTrimmed = '';
			foreach($arrTextChunks as $strChunk) {
				if (strlen($strTrimmed .$strChunk) < $intLength) {
					$strTrimmed .= $strChunk;
				} else {
					$strTrimmed .= "&#8230;";
					break;
				}
			}
			return $strTrimmed;
		}
		return $strText;
	}

}
