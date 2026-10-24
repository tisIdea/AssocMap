<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait DailyHarvestFixture
{
    use MonitoringIncomeFixture { schema as private baseSchema; fixtures as private baseFixtures; }

    private const DAILY_PRODUCT = '99999999-9999-4999-8999-999999999999';

    private function harvestSchema(bool $dailyColumns = true): void
    {
        $this->baseSchema();
        Schema::table('user_invitations', fn (Blueprint $table) => $table->timestamps());
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id'); $table->string('action_type');
            $table->string('module'); $table->string('entity_type'); $table->string('entity_id');
            $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->timestampTz('performed_at');
        });
        if (! $dailyColumns) return;
        Schema::table('monitoring_production', function (Blueprint $table) {
            $table->string('quantity_source')->default('aggregate'); $table->bigInteger('harvest_version')->default(0);
            $table->decimal('target_output', 14, 2)->nullable()->change();
            $table->unique(['monitoring_record_id', 'project_product_id']);
        });
        Schema::create('monitoring_harvest_entries', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->date('harvest_date');
            $table->decimal('quantity', 14, 2); $table->text('remarks')->nullable();
            $table->uuid('created_by'); $table->uuid('updated_by'); $table->timestamps();
            $table->timestampTz('voided_at')->nullable(); $table->uuid('voided_by')->nullable(); $table->text('void_reason')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_monitoring_harvest_active_date ON monitoring_harvest_entries
            (monitoring_production_id, harvest_date) WHERE voided_at IS NULL');
    }

    private function harvestFixtures(): void
    {
        $this->baseFixtures();
        DB::table('project_products')->insert(['id' => self::DAILY_PRODUCT, 'project_id' => self::PROJECT,
            'product_name' => 'Synthetic daily product', 'product_type' => 'raw', 'default_unit' => 'pieces', 'is_active' => true]);
        DB::table('association_accounts')->insert(['id' => self::ACCOUNT, 'association_id' => self::ASSOCIATION,
            'user_id' => self::ACCOUNT, 'is_active' => true]);
    }

    private function harvestReportFixtures(): void
    {
        foreach (['saad_components', 'fisheries_project_categories'] as $name) {
            Schema::create($name, function (Blueprint $table) { $table->integer('id')->primary(); $table->string('name'); });
            DB::table($name)->insert(['id' => 1, 'name' => 'Synthetic category']);
        }
        Schema::create('saad_subcomponents', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('component_id'); });
        Schema::create('saad_activities', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('subcomponent_id'); });
        DB::table('saad_subcomponents')->insert(['id' => 1, 'component_id' => 1]);
        DB::table('saad_activities')->insert(['id' => 1, 'subcomponent_id' => 1]);
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('saad_activity_id')->default(1); $table->integer('fisheries_category_id')->default(1);
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->uuid('id')->primary(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('municipality_id'); $table->string('name'); });
        Schema::create('areas', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('barangay_id'); });
        Schema::table('associations', fn (Blueprint $table) => $table->uuid('area_id')->nullable());
        DB::table('municipalities')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic town']);
        DB::table('barangays')->insert(['id' => self::ASSOCIATION, 'municipality_id' => self::ASSOCIATION, 'name' => 'Synthetic barangay']);
        DB::table('areas')->insert(['id' => self::ASSOCIATION, 'barangay_id' => self::ASSOCIATION]);
        DB::table('associations')->update(['area_id' => self::ASSOCIATION]);
    }
}
