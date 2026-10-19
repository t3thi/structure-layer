<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * "View" of a shadow page (page tree context menu, Layout module): the structure layer has no
 * frontend (/_structure/ is disabled), so the preview shows the Editing Language, or the first
 * existing translation in site-language order if the page lacks it.
 *
 * Fixtures/PageModule.csv: page 1 (de, en), page 20 (de "/seite-b" only).
 */
final class PagePreviewTest extends AbstractStructureLayerTestCase
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
    public function shadowPagePreviewsThePrimaryLanguageByDefault(): void
    {
        self::assertSame('https://structure.localhost/', $this->previewUri(1));
        self::assertSame('https://structure.localhost/seite-b', $this->previewUri(20));
    }

    #[Test]
    public function shadowPagePreviewsTheEditingLanguage(): void
    {
        $this->setEditingLanguage(2);

        self::assertSame('https://structure.localhost/en/', $this->previewUri(1));
    }

    #[Test]
    public function pageMissingInTheEditingLanguagePreviewsTheFirstExistingTranslation(): void
    {
        $this->setEditingLanguage(2);

        self::assertSame('https://structure.localhost/seite-b', $this->previewUri(20));
    }

    #[Test]
    public function explicitLanguageIsKept(): void
    {
        $this->setEditingLanguage(2);

        self::assertSame(
            'https://structure.localhost/',
            (string)PreviewUriBuilder::create(1)->withLanguage(1)->buildUri()
        );
    }

    private function previewUri(int $pageId): string
    {
        // Like the page tree context menu: built from the (shadow) page record.
        return (string)PreviewUriBuilder::create(BackendUtility::getRecord('pages', $pageId) ?? [])->buildUri();
    }

    private function setEditingLanguage(int $languageId): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        $this->get(EditingLanguage::class)->set($this->backendUser, $site, $languageId);
    }
}
