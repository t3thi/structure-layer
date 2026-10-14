<?php

declare(strict_types=1);

namespace T3thi\StructureLayerExtbaseTest\Controller;

use Psr\Http\Message\ResponseInterface;
use T3thi\StructureLayerExtbaseTest\Domain\Model\Content;
use T3thi\StructureLayerExtbaseTest\Domain\Repository\ContentRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Renders what Extbase returns for the current language as JSON:
 * findAll() headers, the SQL count of findAll(), and findByUid() for the shadow uids in settings.shadowUids.
 */
class ContentController extends ActionController
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $byUid = [];
        foreach (array_filter(array_map(intval(...), explode(',', (string)($this->settings['shadowUids'] ?? '')))) as $uid) {
            $byUid[$uid] = $this->contentRepository->findByUid($uid)?->getHeader();
        }
        $all = $this->contentRepository->findAll();
        return $this->jsonResponse((string)json_encode([
            'list' => array_map(static fn(Content $content): string => $content->getHeader(), $all->toArray()),
            // A fresh, uninitialized result: count() runs a COUNT query instead of counting objects
            'count' => $this->contentRepository->findAll()->count(),
            'byUid' => $byUid,
        ]));
    }
}
