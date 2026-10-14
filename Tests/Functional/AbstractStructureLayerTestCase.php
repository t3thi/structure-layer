<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\ResponseContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractStructureLayerTestCase extends FunctionalTestCase
{
    protected const SITE_IDENTIFIER = 'structure-site';

    protected array $testExtensionsToLoad = ['t3thi/structure-layer'];

    protected function installSiteConfiguration(string $fixtureName = 'structure-site'): void
    {
        $targetPath = Environment::getConfigPath() . '/sites/' . self::SITE_IDENTIFIER;
        GeneralUtility::rmdir($targetPath, true);
        GeneralUtility::mkdir_deep($targetPath);
        copy(__DIR__ . '/Fixtures/Sites/' . $fixtureName . '/config.yaml', $targetPath . '/config.yaml');
    }

    /**
     * @return array{status: int, scope: array<string, mixed>, records: array<string, array<string, mixed>>, structure: array<string, mixed>}
     */
    protected function renderFrontend(string $url): array
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest($url));
        $result = ['status' => $response->getStatusCode(), 'scope' => [], 'records' => [], 'structure' => []];
        if ($result['status'] !== 200) {
            return $result;
        }
        $content = ResponseContent::fromString((string)$response->getBody());
        $result['scope'] = [
            'pageUid' => $content->getScopePath('page/uid'),
            'pageTitle' => $content->getScopePath('page/title'),
            'languageId' => $content->getScopePath('languageInfo/id'),
            'overlayType' => $content->getScopePath('languageInfo/overlayType'),
            'fallbackChain' => $content->getScopePath('languageInfo/fallbackChain'),
            // Only filled by Frontend/Fixtures/StructureLayer.typoscript
            'menu' => $content->getScopePath('menu'),
            'languageMenu' => $content->getScopePath('languageMenu'),
            'recordLinks' => $content->getScopePath('recordLinks'),
        ];
        $result['records'] = $content->getSection('Default')->getRecords();
        $result['structure'] = $content->getSection('Default')->getStructure();
        return $result;
    }

    /**
     * @param array{status: int, scope: array<string, mixed>, records: array<string, array<string, mixed>>, structure: array<string, mixed>} $renderResult
     * @return list<string>
     */
    protected function renderedContentHeaders(array $renderResult): array
    {
        $headers = [];
        foreach ($renderResult['records'] as $identifier => $record) {
            if (str_starts_with((string)$identifier, 'tt_content:')) {
                $headers[] = (string)($record['header'] ?? '');
            }
        }
        return $headers;
    }
}
