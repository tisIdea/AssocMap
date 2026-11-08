<?php

namespace Tests\Feature;

use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceHeadingTest extends TestCase
{
    public function test_existing_plain_header_keeps_its_markup_and_escaped_labels(): void
    {
        $html = view('partials.page-header', ['title' => '<script>Title</script>', 'description' => 'Recorded scope'])->render();
        $this->assertStringNotContainsString('workspace-page-heading', $html);
        $this->assertStringContainsString('&lt;script&gt;Title&lt;/script&gt;', $html);
    }

    public function test_card_header_reuses_the_shared_markup_and_retains_actions_and_scope(): void
    {
        $html = view('partials.page-header', [
            'headingCard' => true, 'title' => 'Reports & Analytics', 'description' => 'Recorded scope',
            'actions' => new HtmlString('<button type="button" data-report-filter-toggle>Filters</button>'),
            'headerContext' => new HtmlString('<span>Your association · View only</span>'),
        ])->render();
        $query = $this->query($html);
        $this->assertSame(1, $query->query('//div[contains(@class,"card") and contains(@class,"workspace-page-heading")]/header[contains(@class,"page-header")]/div/h1')->length);
        $this->assertSame(1, $query->query('//header/div[contains(@class,"page-header-actions")]/button[@data-report-filter-toggle]')->length);
        $this->assertStringContainsString('Your association · View only', $html);
    }

    public static function gisRoles(): array
    {
        return [['admin', true, false], ['field-officer', true, false], ['association', false, false], ['public', false, true]];
    }

    #[DataProvider('gisRoles')]
    public function test_gis_header_keeps_each_roles_existing_actions(string $role, bool $manager, bool $public): void
    {
        $html = view('partials.gis-workspace', [
            'gisBaseUrl' => '/'.$role.'/gis', 'gisManager' => $manager, 'gisPublic' => $public,
            'gisTitle' => 'GIS Mapping', 'gisDescription' => 'Recorded locations', 'gisSiteTypes' => [],
        ])->render();
        $query = $this->query($html);
        $heading = '//div[contains(@class,"workspace-page-heading")]/header';
        $this->assertSame(1, $query->query($heading.'/div/h1')->length);
        $this->assertSame($manager ? 1 : 0, $query->query($heading.'//a[@href="/'.$role.'/gis/create"]')->length);
        $this->assertSame($manager ? 0 : 1, $query->query($heading.'//span[contains(@class,"gis-access-label")]')->length);
    }

    private function query(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
