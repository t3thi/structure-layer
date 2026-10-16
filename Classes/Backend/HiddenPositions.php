<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Backend;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Schema\LanguageMarker;

/**
 * Structural positions a language column of the Layout module does not show (plan §5, Phase 5,
 * move disambiguation): per language and colPos, the uid of a visible element (0 = top of the
 * column) => the shadows between it and the next visible element. A drop after that element is
 * ambiguous: the moved element can land before or after each of them, in every language.
 */
final readonly class HiddenPositions
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ShadowLabelResolver $shadowLabelResolver,
    ) {}

    /**
     * @param list<int> $languageIds languages > 0 to compute the columns for
     * @return array<int, array<int, array<int, list<array{uid: int, label: string, language: string, flag: string}>>>>
     */
    public function forPage(int $pageId, array $languageIds, int $workspaceId = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));
        $rows = $queryBuilder->select('uid', 'colPos', 'sys_language_uid', 'l18n_parent')->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->orderBy('sorting')->addOrderBy('uid')
            ->executeQuery()->fetchAllAssociative();

        $structure = [];
        $translations = [];
        foreach ($rows as $row) {
            $languageId = (int)$row['sys_language_uid'];
            if ($languageId === 0 || $languageId === LanguageMarker::ALL_LANGUAGES) {
                $structure[] = $row;
            } elseif ((int)$row['l18n_parent'] > 0) {
                $translations[(int)$row['l18n_parent']][$languageId] ??= (int)$row['uid'];
            }
        }

        $map = [];
        foreach ($languageIds as $languageId) {
            $anchors = [];
            foreach ($structure as $row) {
                $colPos = (int)$row['colPos'];
                $uid = (int)$row['uid'];
                $anchors[$colPos] ??= 0;
                if ((int)$row['sys_language_uid'] === LanguageMarker::ALL_LANGUAGES) {
                    // shown in every column
                    $anchors[$colPos] = $uid;
                } elseif (isset($translations[$uid][$languageId])) {
                    $anchors[$colPos] = $translations[$uid][$languageId];
                } else {
                    $map[$languageId][$colPos][$anchors[$colPos]][] = $this->describe($uid, $pageId, $workspaceId);
                }
            }
        }
        return $map;
    }

    /**
     * @return array{uid: int, label: string, language: string, flag: string}
     */
    private function describe(int $shadowUid, int $pageId, int $workspaceId): array
    {
        $resolved = $this->shadowLabelResolver->resolve('tt_content', $shadowUid, $pageId, null, $workspaceId);
        return [
            'uid' => $shadowUid,
            'label' => $resolved['label'] ?? '',
            'language' => $resolved !== null ? $resolved['language']->getTitle() : '',
            'flag' => $resolved !== null ? $resolved['language']->getFlagIdentifier() : '',
        ];
    }
}
