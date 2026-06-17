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
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Component\HttpFoundation\Response;

use Contao\BackendTemplate;
use Contao\Config;
use Contao\Database;
use Contao\Environment;
use Contao\FilesModel;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\ModuleSearch;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\Pagination;
use Contao\Search;
use Contao\SearchResult;
use Contao\StringUtil;
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

		// Expose ids so the JS live-search can target the lightweight
		// /_zyppy_search endpoint (which skips the full-page render).
		global $objPage;
		$this->Template->moduleId = $this->id;
		$this->Template->currentPageId = $objPage->id ?? 0;

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
					$arrPages[] = Database::getInstance()->getChildRecords($intPageId, 'tl_page');
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
				$arrPages = Database::getInstance()->getChildRecords($objPage->rootId, 'tl_page');
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

				$objResult = new SearchResult([]);
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
					throw new ResponseException(new Response('', 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Zyppy-More' => '0']));
				}

				$this->Template->header = sprintf($GLOBALS['TL_LANG']['MSC']['sEmpty'], $strKeywords);
				$this->Template->duration = System::getFormattedNumber($query_endtime - $query_starttime, 3) . ' ' . $GLOBALS['TL_LANG']['MSC']['seconds'];

				return;
			}

			$from = 1;
			$to = $count;

			// AJAX lazy loading: return a slice defined by offset/limit so the
			// client can fetch results in batches as the user scrolls.
			if ($boolAjax)
			{
				$intOffset = max(0, (int) Input::get('zyppy_offset'));

				// Batch size is configured per module (lazyLoadLimit), default 20.
				$intLimit = (int) $this->lazyLoadLimit;

				if ($intLimit < 1)
				{
					$intLimit = 20;
				}
				elseif ($intLimit > 200)
				{
					$intLimit = 200;
				}

				// Past the end — return nothing so the client stops requesting.
				if ($intOffset >= $count)
				{
					throw new ResponseException(new Response('', 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Zyppy-More' => '0']));
				}

				$from = $intOffset + 1;
				$to = min($intOffset + $intLimit, $count);
			}
			// Pagination
			elseif ($this->perPage > 0)
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

			// Fetch the result slice and the relevance denominator. Subclasses
			// (e.g. weighted search) override fetchResults() to reorder the full
			// result set before it is sliced.
			[$arrResult, $dblMaxRelevance] = $this->fetchResults($objResult, $from, $to, $count);

			// Get the results
			foreach (array_keys($arrResult) as $i)
			{

				$objResultPage = PageModel::findByPk($arrResult[$i]['pid']);

				// Fall back to the full-page template (then search_default) when
				// the AJAX template is empty, so live results match the on-page ones.
				$strTemplate = ($boolAjax ? ($this->ajaxTpl ?: $this->searchTpl) : $this->searchTpl);

				$objTemplate = new FrontendTemplate($strTemplate ? $strTemplate : 'search_default');
				$objTemplate->setData($arrResult[$i]);
				$objTemplate->href = $arrResult[$i]['url'];
				$objTemplate->link = $arrResult[$i]['title'];
				$objTemplate->url = StringUtil::specialchars(urldecode($arrResult[$i]['url']), true, true);
				$objTemplate->title = StringUtil::specialchars(StringUtil::stripInsertTags(($arrResult[$i]['title'] ?? '')));
				// Use the absolute index so first/last/even-odd stay correct
				// across lazy-loaded batches.
				$intAbs = ($from - 1) + $i;
				$objTemplate->class = ($intAbs == 0 ? 'first ' : '') . ($intAbs == $count - 1 ? 'last ' : '') . (($intAbs % 2 == 0) ? 'even' : 'odd');
				$objTemplate->relevance = sprintf($GLOBALS['TL_LANG']['MSC']['relevance'], number_format($arrResult[$i]['relevance'] / $dblMaxRelevance * 100, 2) . '%');
				$objTemplate->unit = $GLOBALS['TL_LANG']['UNITS'][1];


				if ($objResultPage) {
					// Automatically detect news articles by alias — no zyppy_news flag required on the page.
					// Strip query string before extracting alias so URLs like /page?cid=123 still work.
					$strNewsAlias = basename(strtok($arrResult[$i]['url'], '?'), '.html');
					if ($strNewsAlias) {
						$objNewsModel = NewsModel::findOneBy('alias', $strNewsAlias);

						if ($objNewsModel) {
							$objTemplate->isNews = 1;
							if ($objNewsModel->addImage && $objNewsModel->singleSRC) {
								$uuid = StringUtil::binToUuid($objNewsModel->singleSRC);
								$objFile = FilesModel::findByUuid($uuid);
								if ($objFile) {
									$rendered = $this->renderSearchImage($objFile->path);
									if ($rendered !== null) {
										$objTemplate->newsImageHtml = $rendered;
									} else {
										$objTemplate->newsImage = $objFile->path;
									}
								}
							}
							if ($this->formatNewsTeaser) {
								$objTemplate->newsTeaser = $this->formatText($objNewsModel->teaser, $this->newsTeaserLimit);
							} else {
								$objTemplate->newsTeaser = strip_tags($objNewsModel->teaser);
							}
						}
					}
					$objTemplate->isPage = 1;
					if ($objResultPage->page_image) {
						$uuid = StringUtil::binToUuid($objResultPage->page_image);
						$objFile = FilesModel::findByUuid($uuid);
						if ($objFile) {
							$rendered = $this->renderSearchImage($objFile->path);
							if ($rendered !== null) {
								$objTemplate->pageImageHtml = $rendered;
							} else {
								$objTemplate->pageImage = $objFile->path;
							}
						}
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
				throw new ResponseException(new Response($this->Template->results, 200, [
					'Content-Type' => 'text/html; charset=UTF-8',
					'X-Zyppy-More' => ($to < $count) ? '1' : '0',
					'X-Zyppy-Total' => (string) $count,
				]));
			}
		}
	}

	/**
	 * Fetch the result rows for the requested range together with the relevance
	 * denominator (the top hit = 100%).
	 *
	 * Subclasses can override this to reorder the full result set before it is
	 * sliced — e.g. weighted search sorts by weight. Returns a tuple of
	 * array(array $results, float $maxRelevance).
	 *
	 * @param \Contao\SearchResult $objResult
	 */
	protected function fetchResults($objResult, int $from, int $to, int $count): array
	{
		$arrResult = $objResult->getResults($to - $from + 1, $from - 1);

		// Results are already ordered by relevance, so the first row is the most
		// relevant. For lazy-loaded batches beyond the first, fetch it explicitly
		// so the percentages stay consistent across batches.
		$dblMaxRelevance = $arrResult[0]['relevance'] ?? 0;

		if ($from > 1)
		{
			$arrTop = $objResult->getResults(1, 0);

			if (!empty($arrTop) && $arrTop[0]['relevance'] > 0)
			{
				$dblMaxRelevance = $arrTop[0]['relevance'];
			}
		}

		if ($dblMaxRelevance <= 0)
		{
			$dblMaxRelevance = 1;
		}

		return array($arrResult, $dblMaxRelevance);
	}

	/**
	 * Render a search result image as a full <picture> or <img> element,
	 * applying the module's imgSize setting (resize + WebP conversion).
	 *
	 * Returns the HTML string on success, or null when no size is configured
	 * or the image cannot be processed (caller should fall back to the raw path).
	 */
	protected function renderSearchImage(string $path): ?string
	{
		$size = StringUtil::deserialize($this->imgSize);

		if (empty($size)) {
			return null;
		}

		// Named sizes are stored as a numeric string ID (e.g. '3').
		// Cast to int so the picture factory resolves the tl_image_size record
		// rather than looking for a size named '3'.
		if (!is_array($size) && is_numeric($size)) {
			$size = (int) $size;
		}

		$container = System::getContainer();

		try {
			$figure = $container
				->get('contao.image.studio')
				->createFigureBuilder()
				->fromPath($container->getParameter('kernel.project_dir') . '/' . $path, true)
				->setSize($size)
				->build();

			// applyLegacyTemplateData populates 'picture' with img + WebP sources
			$data = new \stdClass();
			$figure->applyLegacyTemplateData($data);

			// Build <picture> element when WebP (or other format) sources exist
			if (!empty($data->picture['sources'])) {
				$html = '<picture>';

				foreach ($data->picture['sources'] as $source) {
					$html .= '<source';
					foreach (['srcset', 'type', 'media', 'sizes'] as $attr) {
						if (!empty($source[$attr])) {
							$html .= ' ' . $attr . '="' . htmlspecialchars((string) $source[$attr]) . '"';
						}
					}
					$html .= '>';
				}

				$img = $data->picture['img'] ?? [];
				$html .= '<img class="page_image"';
				foreach (['src', 'width', 'height', 'loading', 'alt'] as $attr) {
					if (isset($img[$attr]) && $img[$attr] !== '') {
						$html .= ' ' . $attr . '="' . htmlspecialchars((string) $img[$attr]) . '"';
					}
				}
				$html .= '></picture>';

				return $html;
			}

			// No additional sources — plain img with processed src
			return '<img class="page_image"'
				. ' src="' . htmlspecialchars((string) ($data->src ?? $path)) . '"'
				. (!empty($data->width) ? ' width="' . (int) $data->width . '"' : '')
				. (!empty($data->height) ? ' height="' . (int) $data->height . '"' : '')
				. (!empty($data->loading) ? ' loading="' . htmlspecialchars($data->loading) . '"' : '')
				. '>';

		} catch (\Exception $e) {
			$container->get('monolog.logger.contao.error')->error(
				'ZyppySearch: renderSearchImage failed for "' . $path . '": ' . $e->getMessage()
			);

			return null;
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
