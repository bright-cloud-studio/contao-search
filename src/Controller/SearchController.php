<?php

/**
 * @copyright  Bright Cloud Studio
 * @author     Bright Cloud Studio
 * @package    contao-search
 * @license    LGPL-3.0+
 * @see        https://github.com/bright-cloud-studio/contao-search
 */

namespace Bcs\SearchBundle\Controller;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Module;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lightweight endpoint for the live (AJAX) search.
 *
 * Hitting the normal page URL forces Contao to boot the whole front end —
 * layout, navigation and every other module on the page — before the search
 * module runs and short-circuits. That full-page render is what makes live
 * search feel slow. This route runs ONLY the search module, reusing its
 * existing AJAX path (which throws a ResponseException carrying the results
 * HTML), so the heavy page render is skipped entirely.
 */
class SearchController
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function __invoke(Request $request): Response
    {
        // Inject the framework via DI — System::getContainer() is null here
        // because this controller runs before Contao has booted its framework.
        $this->framework->initialize();

        $container = System::getContainer();

        $moduleId = (int) $request->query->get('id');
        $pageId   = (int) $request->query->get('page');

        if ($moduleId < 1) {
            return new Response('', 400, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $objModule = ModuleModel::findByPk($moduleId);

        if ($objModule === null) {
            return new Response('', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // Establish the page context. Root-scoped searches need $objPage->rootId,
        // and the result templates rely on $GLOBALS['TL_LANG'] / TL_LANGUAGE.
        // Prefer an explicit page id; otherwise resolve the root page from the
        // request host so we don't depend on a (possibly overridden) template
        // exposing the page id.
        $objPage = null;

        if ($pageId > 0) {
            $objPage = PageModel::findWithDetails($pageId);
        }

        if ($objPage === null) {
            $objRoot = PageModel::findFirstPublishedRootByHostAndLanguage($request->getHost(), null);

            if ($objRoot !== null) {
                $objPage = PageModel::findWithDetails($objRoot->id);
            }
        }

        if ($objPage !== null) {
            // "global $objPage" inside the module resolves to $GLOBALS['objPage'].
            $GLOBALS['objPage'] = $objPage;
            $request->attributes->set('pageModel', $objPage);

            if ($objPage->language) {
                $GLOBALS['TL_LANGUAGE'] = $objPage->language;
                $request->setLocale(str_replace('-', '_', $objPage->language));
            }
        }

        System::loadLanguageFile('default');

        $strClass = Module::findClass($objModule->type);

        if (!class_exists($strClass)) {
            return new Response('', 500, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        /** @var Module $objModuleInstance */
        $objModuleInstance = new $strClass($objModule);

        $start = microtime(true);

        try {
            // The module throws a ResponseException with the results HTML on the
            // AJAX path (keywords + IS_AJAX + matching zyppy_search parameter).
            $objModuleInstance->generate();
        } catch (ResponseException $e) {
            $response = $e->getResponse();
            $response->headers->set('X-Zyppy-Search-Time', number_format(microtime(true) - $start, 4));

            return $response;
        }

        // No keywords or no match: return an empty 200 like the in-page AJAX path.
        return new Response('', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Zyppy-Search-Time' => number_format(microtime(true) - $start, 4),
        ]);
    }
}
