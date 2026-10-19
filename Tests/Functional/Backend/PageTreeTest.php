<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Backend\Controller\Page\TreeController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Phase 4 (plan §4 H, "Page tree"): the page tree shows shadow pages, so their titles come
 * from a translation – the Editing Language first, otherwise the first available translation
 * in site-language order, marked with that language. A shadow's own (empty) title is never shown.
 *
 * Fixtures/PageModule.csv: page 1 (de "Startseite", en "Home"), page 20 (de "Seite B" only),
 * page 30 (de and en "Walkthrough").
 */
final class PageTreeTest extends AbstractStructureLayerTestCase
{
    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageModule.csv');
        $this->installSiteConfiguration();
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    #[Test]
    public function titlesComeFromThePrimaryLanguageByDefault(): void
    {
        $items = $this->fetchPageTree();

        self::assertSame('Startseite', $items[1]['name']);
        self::assertSame('Seite B', $items[20]['name']);
        self::assertSame('Walkthrough', $items[30]['name']);
        self::assertSame([], $items[1]['statusInformation']);
    }

    #[Test]
    public function titlesComeFromTheEditingLanguage(): void
    {
        $this->setEditingLanguage(2);

        $items = $this->fetchPageTree();

        self::assertSame('Home', $items[1]['name']);
        self::assertSame([], $items[1]['statusInformation']);
    }

    #[Test]
    public function pageMissingInTheEditingLanguageShowsTheFirstAvailableTranslationMarked(): void
    {
        $this->setEditingLanguage(2);

        $items = $this->fetchPageTree();

        self::assertSame('Seite B', $items[20]['name']);
        self::assertCount(1, $items[20]['statusInformation']);
        self::assertSame('flags-de', $items[20]['statusInformation'][0]['icon']);
        self::assertStringContainsString('English', $items[20]['statusInformation'][0]['label']);
        self::assertStringContainsString('Deutsch', $items[20]['statusInformation'][0]['label']);
        self::assertStringContainsString('Deutsch', $items[20]['tooltip']);
    }

    /**
     * Shadows are never hidden (I5): the state shown is the one of the translation the title
     * comes from - the Editing Language, or the fallback shown instead.
     */
    #[Test]
    public function hiddenStateComesFromTheShownTranslation(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->update('pages', ['hidden' => 1], ['uid' => 32]);
        $connection->update('pages', ['hidden' => 1], ['uid' => 21]);

        $items = $this->fetchPageTree();
        self::assertSame('', $items[30]['overlayIcon'], 'de shown, visible');
        self::assertSame('overlay-hidden', $items[20]['overlayIcon'], 'de shown, hidden');

        $this->setEditingLanguage(2);
        $items = $this->fetchPageTree();
        self::assertSame('overlay-hidden', $items[30]['overlayIcon'], 'en shown, hidden');
        self::assertSame('overlay-hidden', $items[20]['overlayIcon'], 'fallback de shown, hidden');
        self::assertSame('', $items[1]['overlayIcon']);
    }

    #[Test]
    public function noStructurePageShowsAnEmptyTitle(): void
    {
        foreach ($this->fetchPageTree() as $identifier => $item) {
            if ($identifier !== 0) {
                self::assertStringNotContainsString('[No title]', $item['name'], 'page ' . $identifier);
            }
        }
    }

    #[Test]
    public function searchFindsShadowPagesByTheirTranslatedTitle(): void
    {
        $items = $this->fetchPageTree(['q' => 'Seite B']);

        self::assertArrayHasKey(20, $items);
        self::assertSame('Seite B', $items[20]['name']);
    }

    /**
     * Inline editing in the page tree sends the new title for the item's uid, the shadow.
     * The title is payload (I1): it is written to the translation the tree shows.
     */
    #[Test]
    public function renamingInThePageTreeWritesTheShownTranslation(): void
    {
        $this->setEditingLanguage(2);

        $this->processDatamap(['pages' => [
            1 => ['title' => 'Home (renamed)'],
            20 => ['title' => 'Seite B (umbenannt)'],
        ]]);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $titles = $queryBuilder->select('uid', 'title')->from('pages')->executeQuery()->fetchAllKeyValue();
        self::assertSame('', $titles[1]);
        self::assertSame('Startseite', $titles[2]);
        self::assertSame('Home (renamed)', $titles[3]);
        self::assertSame('', $titles[20]);
        self::assertSame('Seite B (umbenannt)', $titles[21]);
        self::assertSame('Home (renamed)', $this->fetchPageTree()[1]['name']);
        self::assertSame([], $this->get(InvariantChecker::class)->check());
    }

    private function setEditingLanguage(int $languageId): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        $this->get(EditingLanguage::class)->set($this->backendUser, $site, $languageId);
    }

    /**
     * @param array<string, mixed> $filter "q" switches to the search (filterDataAction)
     * @return array<int, array<string, mixed>> tree items by page uid
     */
    private function fetchPageTree(array $filter = []): array
    {
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        $request = new ServerRequest('https://structure.localhost/typo3/ajax/page/tree/fetchData')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $this->backendUser)
            ->withQueryParams($filter);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = $this->get(TreeController::class);
        $response = isset($filter['q']) ? $controller->filterDataAction($request) : $controller->fetchDataAction($request);
        $items = [];
        foreach (json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR) as $item) {
            $items[(int)$item['identifier']] = $item;
        }
        return $items;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function processDatamap(array $datamap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, [], $this->backendUser);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }
}
