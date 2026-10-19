<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\DataHandling;

use T3thi\StructureLayer\Migration\Migrator;
use T3thi\StructureLayer\StructureLayer;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * A new root page gets Core's automatic site configuration (CreateSiteConfiguration, registered
 * before this hook). That basic site has a visible language 0 and the page is a plain language-0
 * record - DataHandlerHook found no site to pick a translation language from. After the run, the
 * new site is migrated like any classic site (Migrator): language 0 becomes the structure layer,
 * "English" language 1, the page a shadow with its translation.
 */
final class NewSiteMigrationHook
{
    /** @var array<int, true> new page uids that may have got a site */
    private array $newPageIds = [];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly StructureLayer $structureLayer,
        private readonly Migrator $migrator,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    /**
     * @param int|string $id
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'new' || $table !== 'pages' || (int)($fieldArray['sys_language_uid'] ?? 0) !== 0) {
            return;
        }
        $uid = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid > 0 && ((int)($fieldArray['pid'] ?? -1) === 0 || !empty($fieldArray['is_siteroot']))) {
            $this->newPageIds[$uid] = true;
        }
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!$dataHandler->isOuterMostInstance() || $this->newPageIds === []) {
            return;
        }
        $newPageIds = $this->newPageIds;
        $this->newPageIds = [];
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            if (!isset($newPageIds[$site->getRootPageId()]) || $this->structureLayer->isStructureSite($site)) {
                continue;
            }
            try {
                $report = $this->migrator->migrate($site);
                $problems = $report->violations;
            } catch (\Throwable $exception) {
                $problems = [$exception->getMessage()];
            }
            if ($problems !== []) {
                $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                    implode("\n", $problems),
                    'Structure layer: site "' . $site->getIdentifier() . '" not fully migrated',
                    ContextualFeedbackSeverity::WARNING,
                    true
                ));
            }
        }
    }
}
