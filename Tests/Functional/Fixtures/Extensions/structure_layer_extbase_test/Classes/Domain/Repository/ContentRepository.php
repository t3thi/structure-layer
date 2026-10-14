<?php

declare(strict_types=1);

namespace T3thi\StructureLayerExtbaseTest\Domain\Repository;

use T3thi\StructureLayerExtbaseTest\Domain\Model\Content;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Content>
 */
class ContentRepository extends Repository
{
    protected $defaultOrderings = ['sorting' => QueryInterface::ORDER_ASCENDING];
}
