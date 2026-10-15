<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Integrity\InvariantChecker;
use TYPO3\CMS\Backend\Controller\Wizard\LocalizationController;
use TYPO3\CMS\Backend\Localization\LocalizationInstructions;
use TYPO3\CMS\Backend\Localization\LocalizationMode;
use TYPO3\CMS\Backend\Localization\ManualLocalizationHandler;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Phase 3a (plan §6.2): the translation wizard on the structure layer.
 * - The structure layer (0) is never a source: shadows are empty.
 * - Offered content is decided by structural identity (l10n_parent), not by l10n_source.
 * - A page is translated from the chosen source language's page translation.
 */
final class LocalizationWizardTest extends AbstractPageModuleTestCase
{
    #[Test]
    public function structureLayerIsNeverOfferedAsSource(): void
    {
        // en-GB: page 1 exists in de and en (en translated from de, l10n_source = de page)
        self::assertSame([1, 2], $this->sourceLanguageIds('pages', 1, 3));
        // de: only en remains
        self::assertSame([2], $this->sourceLanguageIds('pages', 1, 1));
    }

    #[Test]
    public function wizardOffersOnlyElementsMissingInTheTargetLanguage(): void
    {
        // en <- de: 110 already has en 112 (written directly, l10n_source = 0)
        self::assertSame([121], $this->offeredContentUids(1, targetLanguage: 2, sourceLanguage: 1));
        // de <- en: only the en-only element 126 is missing in de
        self::assertSame([126], $this->offeredContentUids(1, targetLanguage: 1, sourceLanguage: 2));
    }

    #[Test]
    public function pageIsTranslatedFromTheSourceLanguagePageTranslation(): void
    {
        $result = $this->get(ManualLocalizationHandler::class)->processLocalization(new LocalizationInstructions(
            'pages',
            20,
            1,
            2,
            LocalizationMode::TRANSLATE,
            ['selectedRecordUids' => [211]],
        ));

        self::assertTrue($result->jsonSerialize()['success'] ?? false, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $pageTranslation = $this->fetchTranslation('pages', 20, 2);
        self::assertIsArray($pageTranslation);
        self::assertSame(21, (int)$pageTranslation['l10n_source']);
        self::assertStringContainsString('Seite B', (string)$pageTranslation['title']);
        $contentTranslation = $this->fetchTranslation('tt_content', 210, 2);
        self::assertIsArray($contentTranslation);
        self::assertSame(211, (int)$contentTranslation['l10n_source']);
        self::assertSame([], array_map(strval(...), $this->get(InvariantChecker::class)->check()));
    }

    /**
     * Unrestricted: a new page translation is hidden.
     *
     * @return array<string, mixed>|false
     */
    private function fetchTranslation(string $table, int $parentUid, int $languageId): array|false
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')->from($table)->where(
            $queryBuilder->expr()->eq($table === 'pages' ? 'l10n_parent' : 'l18n_parent', $parentUid),
            $queryBuilder->expr()->eq('sys_language_uid', $languageId),
            $queryBuilder->expr()->eq('deleted', 0),
        )->executeQuery()->fetchAssociative();
    }

    /**
     * @return list<int>
     */
    private function sourceLanguageIds(string $recordType, int $recordUid, int $targetLanguage): array
    {
        $response = $this->get(LocalizationController::class)->getSources(
            $this->createWizardRequest(['recordType' => $recordType, 'recordUid' => $recordUid, 'targetLanguage' => $targetLanguage])
        );
        $languages = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return array_values(array_map(static fn(array $language): int => (int)$language['uid'], $languages));
    }

    /**
     * @return list<int>
     */
    private function offeredContentUids(int $pageUid, int $targetLanguage, int $sourceLanguage): array
    {
        $response = $this->get(LocalizationController::class)->getContent(
            $this->createWizardRequest(['pageUid' => $pageUid, 'targetLanguage' => $targetLanguage, 'sourceLanguage' => $sourceLanguage])
        );
        $body = (string)$response->getBody();
        preg_match_all('#"uid":(\d+)#', $body, $matches);
        $uids = array_map(intval(...), $matches[1]);
        sort($uids);
        return array_values(array_unique($uids));
    }

    /**
     * @param array<string, int|string> $queryParams
     */
    private function createWizardRequest(array $queryParams): ServerRequest
    {
        return new ServerRequest('https://structure.localhost/typo3/ajax/records/localize')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/ajax/records/localize', []))
            ->withQueryParams($queryParams);
    }
}
