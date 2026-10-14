<?php

declare(strict_types=1);

namespace T3thi\StructureLayer;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Central facts of the structure layer model (plan §2.2, §3).
 */
final readonly class StructureLayer
{
    /**
     * Tables whose language-0 records are shadows. Inline children (sys_file_reference) and
     * sys_file_metadata stay language-scoped in v1 (plan §4 B).
     */
    public const array SHADOWED_TABLES = ['pages', 'tt_content'];

    /**
     * The language editors see first: the first enabled language, never the structure layer.
     * @todo Phase 3b: prefer the backend user's editing language.
     */
    public function getPrimaryLanguage(Site $site): ?SiteLanguage
    {
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() > 0) {
                return $language;
            }
        }
        return null;
    }
}
