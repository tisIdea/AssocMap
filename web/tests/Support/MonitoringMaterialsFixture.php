<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringMaterialsFixture
{
    use MonitoringIncomeFixture {
        schema as private incomeSchema;
        fixtures as private incomeFixtures;
    }

    private const MATERIAL = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const SECOND_MATERIAL = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const THIRD_MATERIAL = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private function schema(bool $withVersion = true): void
    {
        $this->incomeSchema();
        Schema::create('gis_locations', function (Blueprint $table) { $table->uuid('id')->primary(); });
        (require database_path('migrations/2026_10_27_000000_add_media_photos.php'))->up();

        Schema::table('monitoring_records', function (Blueprint $table) use ($withVersion) {
            if ($withVersion) $table->bigInteger('materials_version')->default(0);
            $table->uuid('created_by')->nullable();
            foreach (['issues_encountered', 'actions_taken', 'overall_remarks'] as $field) $table->text($field)->nullable();
        });
        Schema::table('project_materials', function (Blueprint $table) {
            $table->string('item_name'); $table->decimal('quantity_planned', 14, 2)->default(1);
            $table->decimal('quantity_delivered', 14, 2)->default(0); $table->string('unit')->default('piece');
            $table->decimal('unit_cost', 14, 2)->nullable(); $table->string('delivery_status')->default('planned');
            $table->date('delivery_date')->nullable(); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::table('monitoring_materials', function (Blueprint $table) {
            $table->uuid('project_material_id'); $table->string('condition', 50);
            $table->date('scheduled_maintenance')->nullable(); $table->date('actual_maintenance')->nullable();
            $table->text('remarks')->nullable(); $table->timestamps();
            $table->unique(['monitoring_record_id', 'project_material_id']);
            $table->foreign('monitoring_record_id')->references('id')->on('monitoring_records')->cascadeOnDelete();
            $table->foreign('project_material_id')->references('id')->on('project_materials')->restrictOnDelete();
        });
        Schema::table('monitoring_visit_reports', function (Blueprint $table) {
            $table->boolean('site_validated'); $table->boolean('is_legacy_import')->default(false);
            foreach (['monitored_by', 'conducted_by_user_id', 'created_by', 'updated_by'] as $field) $table->uuid($field)->nullable();
            foreach (['conducted_by', 'issues_encountered', 'actions_taken', 'overall_remarks'] as $field) $table->text($field)->nullable();
            $table->timestamps();
        });
    }

    private function fixtures(): void
    {
        $this->incomeFixtures();
        foreach ([self::MATERIAL, self::SECOND_MATERIAL, self::THIRD_MATERIAL] as $index => $id) {
            DB::table('project_materials')->insert(['id' => $id, 'project_id' => self::PROJECT, 'item_name' => 'Synthetic Material '.($index + 1)]);
        }
        DB::table('association_accounts')->insert(['id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'association_id' => self::ASSOCIATION, 'user_id' => self::ACCOUNT, 'is_active' => true]);
    }
}
