<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3thi\StructureLayer\Backend\Middleware\EditingLanguageInitialization;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Controller\PageLayoutController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\View\PageViewMode;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Phase 3: the Layout module, rendered through PageLayoutController::mainAction() like a real request.
 *
 * Fixtures/PageModule.csv, page 1 (de, en), in structure order:
 * - 100: de 101, en 102 (translated from de)
 * - 110: de 111, en 112 (written directly in en, l10n_source = 0)
 * - 120: de 121 only
 * - 125: en 126 only
 * - 130: "-1" element
 * - 140: de 141, en 142, both hidden
 * Page 20 (de only): 210 with de 211.
 * Page 30 (de, en): empty, for the gate walkthrough.
 */
abstract class AbstractPageModuleTestCase extends AbstractStructureLayerTestCase
{
    protected BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageModule.csv');
        $this->installSiteConfiguration();
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    /**
     * @param array<string, mixed> $queryParams additional query parameters, e.g. ['languages' => [2]]
     */
    protected function renderPageModule(int $pageId, PageViewMode $viewMode, array $queryParams = []): string
    {
        // Every render is a new request: ContentFetcher and page TSconfig are cached per request.
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        $moduleData = new ModuleData('web_layout', ['viewMode' => $viewMode->value], []);
        $request = new ServerRequest('https://structure.localhost/typo3/module/web/layout')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/web/layout', ['_identifier' => 'web_layout']))
            ->withAttribute('site', $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER))
            ->withAttribute('moduleData', $moduleData)
            ->withAttribute('backend.user', $this->backendUser)
            ->withQueryParams(['id' => $pageId] + $queryParams);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $request = $request->withAttribute(
            'pageContext',
            $this->get(PageContextFactory::class)->createFromRequest($request, $pageId, $this->backendUser)
        );
        // Like a real request: the extension's middleware runs after Core's page-context middleware.
        $controller = new class ($this->get(PageLayoutController::class)) implements RequestHandlerInterface {
            public function __construct(private readonly PageLayoutController $controller) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->controller->mainAction($request);
            }
        };
        return (string)$this->get(EditingLanguageInitialization::class)->process($request, $controller)->getBody();
    }

    protected function setPageTsConfig(int $pageId, string $tsConfig): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => $pageId]);
    }

    /**
     * Language ids of the comparison view's header cells, in column order.
     *
     * @return list<string>
     */
    protected function headerLanguageIds(string $html): array
    {
        preg_match_all('#<td\b[^>]*class="[^"]*t3-page-column-lang-name[^"]*"[^>]*data-language-uid="(-?\d+)"#s', $html, $matches);
        return $matches[1];
    }

    protected function headerCell(string $languageId, string $html): string
    {
        preg_match_all('#<td\b[^>]*t3-page-column-lang-name[^>]*>.*?</td>#s', $html, $matches);
        foreach ($matches[0] as $cell) {
            if (str_contains($cell, 'data-language-uid="' . $languageId . '"')) {
                return $cell;
            }
        }
        return '';
    }

    /**
     * The markup of one rendered content element, from its wrapper up to the next element.
     */
    protected function recordElement(int $uid, string $html): string
    {
        $start = strpos($html, 'id="element-tt_content-' . $uid . '"');
        self::assertNotFalse($start, 'tt_content:' . $uid . ' is rendered');
        $end = strpos($html, 'role="group"', $start);
        return substr($html, $start, $end === false ? null : $end - $start);
    }

    protected function newContentUrl(string $markup): string
    {
        preg_match('#<typo3-backend-new-content-element-wizard-button[^>]*url="([^"]*)"#', $markup, $match);
        return html_entity_decode($match[1] ?? '');
    }

    /**
     * Uids of the rendered content elements, in document order.
     *
     * @return list<int>
     */
    protected function renderedContentUids(string $html): array
    {
        preg_match_all('#id="element-tt_content-(\d+)"#', $html, $matches);
        return array_map(intval(...), $matches[1]);
    }

    /**
     * Content rows of the comparison view, as uids per cell.
     *
     * @return list<list<list<int>>>
     */
    protected function contentRows(string $html): array
    {
        $rows = [];
        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#s', $html, $matches);
        foreach ($matches[1] as $row) {
            if (!str_contains($row, 'id="element-tt_content-') && !str_contains($row, 'structure-layer-placeholder')) {
                continue;
            }
            preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $row, $cells);
            $rows[] = array_map(
                static function (string $cell): array {
                    preg_match_all('#id="element-tt_content-(\d+)"#', $cell, $uids);
                    return array_map(intval(...), $uids[1]);
                },
                $cells[1]
            );
        }
        return $rows;
    }

    protected function tableRowContaining(string $needle, string $html): string
    {
        preg_match_all('#<tr\b[^>]*>.*?</tr>#s', $html, $matches);
        foreach ($matches[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }
        return '';
    }
}
