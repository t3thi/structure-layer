<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\DataHandling;

use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 2, scenario 11 (plan §4 H): "hidden" written on a shadow means "in all languages".
 * It is written on every translation in one DataHandler run; the shadow stays visible (I5).
 */
final class HideInAllLanguagesTest extends AbstractDataHandlingTestCase
{
    #[Test]
    public function hidingAShadowHidesEveryTranslation(): void
    {
        $this->processDatamap(['tt_content' => [110 => ['hidden' => 1]]]);
        self::assertSame(0, $this->fetchRecord('tt_content', 110)['hidden']);
        self::assertSame(1, $this->fetchRecord('tt_content', 111)['hidden']);
        self::assertSame(1, $this->fetchRecord('tt_content', 112)['hidden']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function showingAShadowShowsEveryTranslation(): void
    {
        $this->processDatamap(['tt_content' => [110 => ['hidden' => 1]]]);
        $this->processDatamap(['tt_content' => [110 => ['hidden' => 0]]]);
        self::assertSame(0, $this->fetchRecord('tt_content', 111)['hidden']);
        self::assertSame(0, $this->fetchRecord('tt_content', 112)['hidden']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function hidingATranslationHidesOnlyThatLanguage(): void
    {
        $this->processDatamap(['tt_content' => [112 => ['hidden' => 1]]]);
        self::assertSame(0, $this->fetchRecord('tt_content', 110)['hidden']);
        self::assertSame(0, $this->fetchRecord('tt_content', 111)['hidden']);
        self::assertSame(1, $this->fetchRecord('tt_content', 112)['hidden']);
        $this->assertInvariantsHold();
    }

    #[Test]
    public function hidingAShadowPageHidesEveryPageTranslation(): void
    {
        $this->processDatamap(['pages' => [10 => ['hidden' => 1]]]);
        self::assertSame(0, $this->fetchRecord('pages', 10)['hidden']);
        self::assertSame(1, $this->fetchRecord('pages', 11)['hidden']);
        self::assertSame(1, $this->fetchRecord('pages', 12)['hidden']);
        $this->assertInvariantsHold();
    }
}
