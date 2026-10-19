<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InterfaceIconTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->withoutVite();
    }

    public function test_public_and_login_branding_identifies_the_prototype_and_keeps_program_context(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Independent academic Capstone prototype')
            ->assertSee('Program context: SAAD Phase II')
            ->assertSee('community livelihood associations under SAAD Phase II')
            ->assertDontSee('BFAR Region VII');
        $this->get('/login')->assertOk()->assertSee('Capstone Project Prototype')
            ->assertDontSee('SAAD Phase II · Region VII');
    }

    public function test_every_registered_icon_and_legacy_alias_renders_safe_decorative_svg(): void
    {
        $library = require resource_path('ui/icons.php');
        foreach (array_unique([...array_keys($library['icons']), ...array_keys($library['aliases'])]) as $name) {
            $html = Blade::render('<x-icon :name="$name" class="test-icon" />', compact('name'));
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($html), $name);
            $svg = $document->documentElement;
            $this->assertSame('svg', $svg->tagName);
            $this->assertSame($library['aliases'][$name] ?? $name, $svg->getAttribute('data-icon'));
            $this->assertSame('0 0 24 24', $svg->getAttribute('viewBox'));
            $this->assertSame('2', $svg->getAttribute('stroke-width'));
            $this->assertSame('currentColor', $svg->getAttribute('stroke'));
            $this->assertSame('true', $svg->getAttribute('aria-hidden'));
            $this->assertSame('false', $svg->getAttribute('focusable'));
            $this->assertStringContainsString('test-icon', $svg->getAttribute('class'));
            $this->assertGreaterThan(0, $svg->childNodes->length);
            foreach ($svg->getElementsByTagName('*') as $node) {
                $this->assertContains($node->tagName, ['path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon']);
                foreach ($node->attributes as $attribute) {
                    $this->assertFalse(str_starts_with(strtolower($attribute->name), 'on'));
                    $this->assertStringNotContainsString('href', strtolower($attribute->name));
                }
            }
        }
    }

    public function test_static_call_sites_and_module_icons_have_explicit_registry_entries(): void
    {
        $library = require resource_path('ui/icons.php');
        $known = [...array_keys($library['icons']), ...array_keys($library['aliases'])];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-icon\s+name="([a-z0-9-]+)"/', $file->getContents(), $matches);
            foreach ($matches[1] as $name) $this->assertContains($name, $known, $file->getRelativePathname());
        }
        $catalog = require resource_path('ui/admin.php');
        foreach ($catalog['modules'] as $module) $this->assertContains($module['icon'], $known);
    }

    public function test_brand_mark_and_password_visibility_hook_are_preserved(): void
    {
        $mark = Blade::render('<x-assocmap-mark />');
        $this->assertStringContainsString('m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2z M9 3v16 M15 5v16', $mark);
        $this->assertStringContainsString('stroke-width="1.7"', $mark);
        $this->assertStringNotContainsString('data-icon=', $mark);

        $html = view('auth.invitation-password', ['errors' => new \Illuminate\Support\ViewErrorBag])->render();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $this->assertSame(2, $xpath->query('//button[@data-invitation-password-toggle]//svg[@data-icon="eye"]/path[@data-password-eye-off and @hidden]')->length);
    }
}
