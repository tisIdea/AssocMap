<?php

namespace Tests\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

trait AssertsWorkflowNavigation
{
    private function assertWorkflowBack(TestResponse $page, string $expected): void
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $link = (new DOMXPath($dom))->query('//a[@data-workflow-back]')->item(0);
        $this->assertNotNull($link, 'A contextual back link must be available.');
        $actual = $link->getAttribute('href');
        $this->assertSame(parse_url($expected, PHP_URL_PATH), parse_url($actual, PHP_URL_PATH));
        $this->assertSame(parse_url($expected, PHP_URL_FRAGMENT), parse_url($actual, PHP_URL_FRAGMENT));
        parse_str(parse_url($expected, PHP_URL_QUERY) ?? '', $expectedQuery);
        parse_str(parse_url($actual, PHP_URL_QUERY) ?? '', $actualQuery);
        $this->assertEquals($expectedQuery, $actualQuery);
    }
}
