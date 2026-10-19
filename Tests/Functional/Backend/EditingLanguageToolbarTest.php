<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use T3thi\StructureLayer\Backend\Controller\EditingLanguageController;
use T3thi\StructureLayer\Backend\EditingLanguage;
use T3thi\StructureLayer\Backend\ToolbarItems\EditingLanguageToolbarItem;
use T3thi\StructureLayer\Tests\Functional\AbstractStructureLayerTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Top bar: the Editing Language can be switched from everywhere in the backend (the Layout
 * module's language selector is single-select only in the Layout view).
 */
final class EditingLanguageToolbarTest extends AbstractStructureLayerTestCase
{
    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users_admin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageModule.csv');
        $this->installSiteConfiguration();
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    #[Test]
    public function toolbarShowsTheEditingLanguage(): void
    {
        $toolbarItem = $this->toolbarItem();

        self::assertTrue($toolbarItem->checkAccess());
        self::assertTrue($toolbarItem->hasDropDown());
        $item = $toolbarItem->getItem();
        self::assertStringContainsString('Deutsch', $item);
        self::assertStringContainsString('flags-de', $item);
    }

    #[Test]
    public function dropDownOffersEveryLanguageButTheStructureLayer(): void
    {
        $dropDown = $this->toolbarItem()->getDropDown();

        preg_match_all('#data-language-id="(\d+)"#', $dropDown, $matches);
        self::assertSame(['1', '2', '3'], $matches[1]);
        self::assertMatchesRegularExpression('#<button[^>]*active[^>]*data-language-id="1"#s', $dropDown);
        self::assertStringContainsString('data-site="' . self::SITE_IDENTIFIER . '"', $dropDown);
    }

    #[Test]
    public function selectingALanguageSetsTheEditingLanguage(): void
    {
        $request = $this->request()->withParsedBody(['site' => self::SITE_IDENTIFIER, 'language' => '2']);

        $response = $this->get(EditingLanguageController::class)->setAction($request);

        self::assertSame(200, $response->getStatusCode());
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        self::assertSame(2, $this->get(EditingLanguage::class)->get($this->backendUser, $site));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertStringContainsString('English', $payload['item']);
    }

    #[Test]
    public function theStructureLayerCannotBeSelected(): void
    {
        $request = $this->request()->withParsedBody(['site' => self::SITE_IDENTIFIER, 'language' => '0']);

        $response = $this->get(EditingLanguageController::class)->setAction($request);

        self::assertSame(400, $response->getStatusCode());
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE_IDENTIFIER);
        self::assertSame(1, $this->get(EditingLanguage::class)->get($this->backendUser, $site));
    }

    #[Test]
    public function ajaxRouteIsRegistered(): void
    {
        $route = $this->get(Router::class)->getRoute('ajax_structure_layer_editing_language');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods());
    }

    private function toolbarItem(): EditingLanguageToolbarItem
    {
        $toolbarItem = $this->get(EditingLanguageToolbarItem::class);
        $toolbarItem->setRequest($this->request());
        return $toolbarItem;
    }

    private function request(): ServerRequest
    {
        $request = new ServerRequest('https://structure.localhost/typo3/main', 'POST')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/main', ['packageName' => 'typo3/cms-backend']))
            ->withAttribute('backend.user', $this->backendUser);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
