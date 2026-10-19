<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringIncomeFixture
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ACCOUNT = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PROJECT = '55555555-5555-4555-8555-555555555555';
    private const PRODUCT = '66666666-6666-4666-8666-666666666666';
    private const PRODUCTION = '77777777-7777-4777-8777-777777777777';
    private const RECORD = '88888888-8888-4888-8888-888888888888';
    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('status');
            $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('primary_field_officer_id')->nullable(); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('user_id'); $table->boolean('is_active');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('title'); $table->integer('program_year');
            $table->string('status'); $table->timestamp('closed_at')->nullable(); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('project_products', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('product_name'); $table->string('product_type');
            $table->string('default_unit'); $table->boolean('is_active');
        });
        Schema::create('project_materials', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('project_id'); });
        Schema::create('quarters', function (Blueprint $table) { $table->smallInteger('id')->primary(); $table->integer('quarter_number'); $table->string('name'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->integer('year'); $table->smallInteger('quarter_id');
            $table->date('monitoring_date'); $table->uuid('monitored_by'); $table->boolean('site_validated'); $table->string('project_status');
            $table->uuid('updated_by'); $table->bigInteger('income_version')->default(0); $table->timestamps();
        });
        Schema::create('monitoring_production', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->uuid('project_product_id');
            $table->decimal('target_output', 14, 2); $table->decimal('actual_output', 14, 2); $table->string('unit'); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('monitoring_visit_reports', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->date('monitoring_date');
        });
        Schema::create('monitoring_production_monthly', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->smallInteger('month');
            $table->integer('activity_count'); $table->decimal('total_quantity', 14, 2); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('monitoring_income', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->string('period_type');
            $table->smallInteger('month')->nullable(); $table->decimal('gross_income', 14, 2); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('monitoring_materials', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::ACCOUNT => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Association', 'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        DB::table('projects')->insert(['id' => self::PROJECT, 'association_id' => self::ASSOCIATION, 'title' => 'Synthetic Project', 'program_year' => 2026, 'status' => 'ongoing']);
        DB::table('project_products')->insert(['id' => self::PRODUCT, 'project_id' => self::PROJECT, 'product_name' => 'Synthetic fish', 'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true]);
        DB::table('quarters')->insert(['id' => 11, 'quarter_number' => 1, 'name' => 'Q1']);
        DB::table('monitoring_records')->insert(['id' => self::RECORD, 'project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11,
            'monitoring_date' => '2026-01-15', 'monitored_by' => self::OFFICER, 'site_validated' => true, 'project_status' => 'ongoing', 'updated_by' => self::ADMIN]);
        DB::table('monitoring_production')->insert(['id' => self::PRODUCTION, 'monitoring_record_id' => self::RECORD, 'project_product_id' => self::PRODUCT,
            'target_output' => '100.00', 'actual_output' => '5.00', 'unit' => 'kg']);
    }

}
