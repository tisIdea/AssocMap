<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedAdminFormContractTest extends TestCase
{
    private function fields(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    private function repeaterSection(): array
    {
        return ['title' => 'Products', 'repeater' => 'products', 'button' => 'Add Product',
            'fields' => [['name' => 'product_name', 'label' => 'Product Name', 'type' => 'text']]];
    }

    public static function modes(): array
    {
        return ['create' => ['create', ''], 'edit' => ['edit', 'Retained <Product>']];
    }

    #[DataProvider('modes')]
    public function test_shared_form_keeps_field_names_values_prefixes_and_template_rows(string $mode, string $value): void
    {
        $module = ['key' => 'projects', 'title' => 'Projects', 'singular' => 'Project', 'action' => 'Add Project',
            'description' => 'Manage Projects', 'icon' => 'folder-kanban',
            'record' => ['title' => 'Recorded title', 'products' => [['product_name' => 'Retained <Product>']]],
            'sections' => [['title' => 'Basic Information', 'repeater' => null,
                'fields' => [['name' => 'title', 'label' => 'Title', 'type' => 'text']]], $this->repeaterSection()]];
        DB::enableQueryLog(); DB::flushQueryLog();
        $html = view('admin.shared.form', ['mode' => $mode, 'module' => $module, 'errors' => new ViewErrorBag])->render();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $fields = $this->fields($html);
        $this->assertSame($mode === 'edit' ? 'Recorded title' : '', $fields->query('//input[@name="title"]')->item(0)->getAttribute('value'));
        $row = $fields->query('//input[@name="products[0][product_name]"]')->item(0);
        $this->assertSame($value, $row->getAttribute('value'));
        $this->assertSame('projects-'.$mode.'-products-0-product_name', $row->getAttribute('id'));
        $template = $fields->query('//template//input[@name="products[__INDEX__][product_name]"]')->item(0);
        $this->assertSame('projects-'.$mode.'-products-__INDEX__-product_name', $template->getAttribute('id'));
        $this->assertSame('', $template->getAttribute('value'));
        $this->assertSame(1, $fields->query('//button[@data-add-row]')->length);
    }

    public function test_repeater_renders_with_only_its_explicit_inputs_and_nullable_values(): void
    {
        $html = view('admin.shared.repeater-row', ['repeaterSection' => $this->repeaterSection(),
            'formPrefix' => 'projects-edit', 'rowIndex' => 2, 'rowValues' => ['product_name' => null],
            'errors' => new ViewErrorBag])->render();
        $fields = $this->fields($html);
        $input = $fields->query('//input[@name="products[2][product_name]"]')->item(0);
        $this->assertSame('projects-edit-products-2-product_name', $input->getAttribute('id'));
        $this->assertSame('', $input->getAttribute('value'));
        $this->assertSame('3', $fields->query('//span[@data-row-number]')->item(0)->textContent);
    }
}
