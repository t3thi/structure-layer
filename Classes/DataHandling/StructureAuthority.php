<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\DataHandling;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Who may change the shared structure (plan §5, Phase 5 – a prototype, not a permission model).
 *
 * Core checks language access per record, and every shadow is language 0. An editor restricted to
 * some languages could therefore neither create content (its shadow is rejected) nor delete it.
 *
 * - Content operations (create, translate, delete/restore a translation) only touch the editor's
 *   own languages. The shadow writes that follow are system-managed: for the outermost DataHandler
 *   run, language 0 is added to the editor's allowed languages.
 * - Structure operations (move, copy, structural fields, hide/delete in all languages) change every
 *   language. They need the custom permission "structure_layer:structure"; with it, the run may write
 *   every language. The DataHandler hook removes the editor's own requests for other languages
 *   beforehand, so the grant only covers what follows from the structure operation.
 *
 * The grant lives in the user's group data and is withdrawn after the run. An exception in between
 * leaves it in place for the rest of the request (acceptable for the PoC, not for Core).
 */
final class StructureAuthority
{
    public const string PERMISSION = 'structure_layer:structure';

    /** @var array<int, string> spl_object_id(user) => allowed_languages before the grant */
    private array $originalAllowedLanguages = [];

    public function hasStructurePermission(BackendUserAuthentication $backendUser): bool
    {
        return $backendUser->isAdmin() || $backendUser->check('custom_options', self::PERMISSION);
    }

    public function isRestricted(BackendUserAuthentication $backendUser): bool
    {
        return !$backendUser->isAdmin() && trim((string)($backendUser->groupData['allowed_languages'] ?? '')) !== '';
    }

    /**
     * @param bool $allLanguages structure run: every language; otherwise only the structure layer (0)
     */
    public function grant(BackendUserAuthentication $backendUser, bool $allLanguages): void
    {
        if (!$this->isRestricted($backendUser) && !isset($this->originalAllowedLanguages[spl_object_id($backendUser)])) {
            return;
        }
        $this->originalAllowedLanguages[spl_object_id($backendUser)] ??= (string)$backendUser->groupData['allowed_languages'];
        $original = $this->originalAllowedLanguages[spl_object_id($backendUser)];
        // An empty list means "all languages" (BackendUserAuthentication::checkLanguageAccess())
        $backendUser->groupData['allowed_languages'] = $allLanguages ? '' : $original . ',0';
    }

    public function withdraw(BackendUserAuthentication $backendUser): void
    {
        $id = spl_object_id($backendUser);
        if (isset($this->originalAllowedLanguages[$id])) {
            $backendUser->groupData['allowed_languages'] = $this->originalAllowedLanguages[$id];
            unset($this->originalAllowedLanguages[$id]);
        }
    }
}
