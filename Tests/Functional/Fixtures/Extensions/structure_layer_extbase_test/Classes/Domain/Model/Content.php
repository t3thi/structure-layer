<?php

declare(strict_types=1);

namespace T3thi\StructureLayerExtbaseTest\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Content extends AbstractEntity
{
    protected string $header = '';

    public function getHeader(): string
    {
        return $this->header;
    }
}
