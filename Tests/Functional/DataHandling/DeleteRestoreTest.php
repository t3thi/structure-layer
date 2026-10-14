<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenarios 7 and 8 (component E): no shadow without a translation (I4), in both directions.
 */
final class DeleteRestoreTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function deletingTheLastTranslationDeletesTheShadow(): void
    {
        $this->processCmdmap(['tt_content' => [121 => ['delete' => 1]]]);
        self::assertSame(1, $this->fetchRecord('tt_content', 120)['deleted']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function deletingOneOfTwoTranslationsKeepsTheShadow(): void
    {
        $this->processCmdmap(['tt_content' => [112 => ['delete' => 1]]]);
        self::assertSame(0, $this->fetchRecord('tt_content', 110)['deleted']);
        self::assertSame([1], array_keys($this->fetchTranslations('tt_content', 110)));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function deletingTheShadowDeletesAllLanguages(): void
    {
        $this->processCmdmap(['tt_content' => [110 => ['delete' => 1]]]);
        self::assertSame(1, $this->fetchRecord('tt_content', 111)['deleted']);
        self::assertSame(1, $this->fetchRecord('tt_content', 112)['deleted']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function deletingTheLastPageTranslationDeletesTheShadowPage(): void
    {
        $this->processCmdmap(['pages' => [21 => ['delete' => 1]]]);
        self::assertSame(1, $this->fetchRecord('pages', 20)['deleted']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function restoringATranslationRestoresItsShadow(): void
    {
        $this->processCmdmap(['tt_content' => [121 => ['delete' => 1]]]);
        $this->processCmdmap(['tt_content' => [121 => ['undelete' => 1]]]);
        self::assertSame(0, $this->fetchRecord('tt_content', 121)['deleted']);
        self::assertSame(0, $this->fetchRecord('tt_content', 120)['deleted']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function restoringAShadowRestoresTheTranslationsDeletedWithIt(): void
    {
        $this->processCmdmap(['tt_content' => [110 => ['delete' => 1]]]);
        $this->processCmdmap(['tt_content' => [110 => ['undelete' => 1]]]);
        self::assertSame([1, 2], array_keys($this->fetchTranslations('tt_content', 110)));
        $this->assertInvariantsHold();
    }

    #[Test]
    public function restoringAShadowKeepsTranslationsDeletedEarlier(): void
    {
        $this->processCmdmap(['tt_content' => [112 => ['delete' => 1]]]);
        // the English record was deleted in an earlier request
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->update('tt_content', ['tstamp' => $GLOBALS['EXEC_TIME'] - 3600], ['uid' => 112]);
        $this->processCmdmap(['tt_content' => [110 => ['delete' => 1]]]);
        $this->processCmdmap(['tt_content' => [110 => ['undelete' => 1]]]);
        self::assertSame([1], array_keys($this->fetchTranslations('tt_content', 110)));
        $this->assertInvariantsHold();
    }
}
