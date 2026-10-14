<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\EventListener;

use T3thi\StructureLayer\Frontend\TranslationLookup;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Domain\Event\AfterRecordLanguageOverlayEvent;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\LanguageMarker;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Chain-driven output for records (plan §4 G):
 *
 * - A shadow (language 0) is never output. Without overlays (no chain), a shadow fetched by
 *   uid (RECORDS, shortcut, findByUid, ...) is resolved to its translation.
 * - A translation of the requested language that exists but is disabled blocks the fallback:
 *   nothing is output at that position.
 *
 * Pages are handled by EnforcePageOutputPolicy; a page overlay must never be null.
 */
#[AsEventListener('structure-layer/record-output-policy')]
final readonly class EnforceRecordOutputPolicy
{
    public function __construct(
        private TranslationLookup $translationLookup,
        private TcaSchemaFactory $tcaSchemaFactory,
        private Context $context,
    ) {}

    public function __invoke(AfterRecordLanguageOverlayEvent $event): void
    {
        $table = $event->getTable();
        $languageAspect = $event->getLanguageAspect();
        $requestedLanguageId = $languageAspect->getContentId();
        if ($table === 'pages' || $requestedLanguageId <= 0) {
            return;
        }
        $languageCapability = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Language);
        $languageField = $languageCapability->getLanguageField()->getName();
        $transOrigPointerField = $languageCapability->getTranslationOriginPointerField()->getName();

        if (!$event->overlayingWasAttempted()) {
            $record = $event->getRecord();
            if ((int)($record[$languageField] ?? 0) === 0 && isset($record['uid'])) {
                $event->setLocalizedRecord($this->resolveShadow($table, (int)$record['uid'], $languageAspect));
            }
            return;
        }

        $localizedRecord = $event->getLocalizedRecord();
        if ($localizedRecord === null) {
            return;
        }
        $outputLanguageId = (int)($localizedRecord[$languageField] ?? 0);
        if ($outputLanguageId === 0) {
            // OVERLAYS_MIXED keeps the language-0 row if no translation in the chain is available
            $event->setLocalizedRecord(null);
            return;
        }
        if ($outputLanguageId === LanguageMarker::ALL_LANGUAGES || $outputLanguageId === $requestedLanguageId) {
            return;
        }
        // Fallback record: the overlay query uses enable fields, so a disabled translation in the
        // requested language looks like a missing one. Distinguish both here.
        $shadowUid = (int)($localizedRecord[$transOrigPointerField] ?? 0);
        if ($shadowUid > 0 && $this->translationLookup->translationExists($table, $shadowUid, $requestedLanguageId)) {
            $event->setLocalizedRecord(null);
        }
    }

    /**
     * Same rules as an overlay along the chain, but returning the translation itself, because
     * without overlays records are identified by their own uid (plan §4 G, difference #4).
     *
     * @return array<string, mixed>|null
     */
    private function resolveShadow(string $table, int $shadowUid, LanguageAspect $languageAspect): ?array
    {
        $requestedLanguageId = $languageAspect->getContentId();
        $chain = array_values(array_map(
            intval(...),
            array_filter($languageAspect->getFallbackChain(), MathUtility::canBeInterpretedAsInteger(...))
        ));
        $languageIds = array_values(array_unique([$requestedLanguageId, ...array_filter($chain, static fn(int $id): bool => $id > 0)]));
        $visibleTranslations = $this->translationLookup->findVisibleTranslations($table, $shadowUid, $languageIds, $this->context);
        if (isset($visibleTranslations[$requestedLanguageId])) {
            return $visibleTranslations[$requestedLanguageId];
        }
        if ($this->translationLookup->translationExists($table, $shadowUid, $requestedLanguageId)) {
            return null;
        }
        foreach ($languageIds as $languageId) {
            if (isset($visibleTranslations[$languageId])) {
                return $visibleTranslations[$languageId];
            }
        }
        return null;
    }
}
