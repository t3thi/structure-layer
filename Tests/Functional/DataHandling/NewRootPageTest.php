<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A new root page gets Core's automatic site configuration (CreateSiteConfiguration). That basic
 * site has a visible language 0, so it is migrated right away: language 0 becomes the structure
 * layer, the page a shadow with a translation in the former default language.
 */
final class NewRootPageTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function newRootPageGetsAStructureLayerSite(): void
    {
        $dataHandler = $this->processDatamap([
            'pages' => [
                'NEW1' => ['pid' => 0, 'doktype' => 1, 'title' => 'New root', 'hidden' => 0],
            ],
        ]);
        $pageId = (int)$dataHandler->substNEWwithIDs['NEW1'];

        $site = $this->get(SiteFinder::class)->getSiteByRootPageId($pageId);
        $languages = $site->getConfiguration()['languages'];
        self::assertSame(0, $languages[0]['languageId']);
        self::assertFalse($languages[0]['enabled']);
        self::assertSame('/_structure/', $languages[0]['base']);
        self::assertSame(1, $languages[1]['languageId']);
        self::assertSame('English', $languages[1]['title']);
        self::assertSame('/', $languages[1]['base']);

        $shadow = $this->fetchRecord('pages', $pageId);
        self::assertSame(0, $shadow['sys_language_uid']);
        self::assertSame('', $shadow['title']);
        $translations = $this->fetchTranslations('pages', $pageId);
        self::assertSame([1], array_keys($translations));
        self::assertSame('New root', $translations[1]['title']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function newPageInAnExistingStructureSiteLeavesTheSiteAlone(): void
    {
        $before = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER)->getConfiguration();

        $this->processDatamap(['pages' => ['NEW1' => ['pid' => 1, 'doktype' => 1, 'title' => 'Sub page']]]);

        self::assertSame($before['languages'], $this->get(SiteFinder::class)->getAllSites(false)[self::SITE_IDENTIFIER]->getConfiguration()['languages']);
    }
}
