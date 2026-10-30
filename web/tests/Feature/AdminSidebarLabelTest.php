<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AdminSidebarLabelTest extends TestCase
{
    public function test_admin_member_link_keeps_its_destination_icon_and_active_state(): void
    {
        $this->app['request']->setRouteResolver(fn () => (new Route('GET', 'admin/members', []))->name('admin.members.index'));
        $catalog = require resource_path('ui/admin.php');
        $html = view('partials.admin-sidebar', ['catalog' => $catalog])->render();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $link = (new DOMXPath($dom))->query('//a[@data-nav="members"]')->item(0);
        $this->assertNotNull($link);
        $this->assertSame('Registered Members', trim($link->textContent));
        $this->assertSame(route('admin.members.index'), $link->getAttribute('href'));
        $this->assertSame('Registered Members', $link->getAttribute('aria-label'));
        $this->assertSame('Registered Members', $link->getAttribute('title'));
        $this->assertSame('page', $link->getAttribute('aria-current'));
        $this->assertStringContainsString('active', $link->getAttribute('class'));
        $this->assertSame(1, $link->getElementsByTagName('svg')->length);
        $this->assertSame('users-round', $catalog['modules']['members']['icon']);
        $this->assertSame('Member Management', $catalog['modules']['members']['title']);
    }
}
