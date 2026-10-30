<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesEmailChangeFixture
{
    use CreatesAccountLifecycleSchema;

    protected function createEmailChangeSchema(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT, name TEXT, description TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_profiles (id TEXT PRIMARY KEY, role_id INTEGER REFERENCES roles(id), display_name TEXT, is_active BOOLEAN,
                activated_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE associations (id TEXT PRIMARY KEY, primary_field_officer_id TEXT, name TEXT, status TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE association_accounts (id TEXT PRIMARY KEY, user_id TEXT, association_id TEXT, is_active BOOLEAN, ended_at TEXT, ended_by TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_invitations (id TEXT PRIMARY KEY, auth_user_id TEXT, role_id INTEGER, association_id TEXT, email TEXT, display_name TEXT,
                status TEXT, last_error_code TEXT, accepted_at TEXT, last_attempt_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE audit_logs (id TEXT PRIMARY KEY, actor_user_id TEXT, action_type TEXT, module TEXT, entity_type TEXT, entity_id TEXT,
                old_values TEXT, new_values TEXT, performed_at TEXT);
            CREATE TABLE email_change_requests (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES user_profiles(id), old_email TEXT NOT NULL,
                new_email TEXT NOT NULL, requested_by TEXT REFERENCES user_profiles(id), status TEXT NOT NULL DEFAULT 'pending', confirmed_at TEXT,
                cancelled_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE UNIQUE INDEX uq_email_change_pending_per_user ON email_change_requests(user_id) WHERE status='pending';
            SQL);
        $this->createReactivationSchema();
        $this->createVerifiedEmailChangeSchema();
        DB::statement("ATTACH DATABASE ':memory:' AS auth");
        DB::statement('CREATE TABLE auth.users (id TEXT PRIMARY KEY, email TEXT NOT NULL, email_change TEXT)');
        DB::statement('CREATE TABLE auth.sessions (id TEXT PRIMARY KEY, user_id TEXT, created_at TEXT)');
    }
}
