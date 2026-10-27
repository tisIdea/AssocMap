<?php

namespace Tests\Support;

use App\Exceptions\MediaLifecycleException;
use App\Models\MediaPhoto;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Media\PhotoLifecycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;

trait CreatesMediaFixture
{
    private function mediaFixture(): void
    {
        foreach (['user_profiles', 'projects', 'gis_locations', 'monitoring_visit_reports', 'monitoring_materials'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->boolean('is_published')->default(false);
            });
        }
        (require database_path('migrations/2026_10_27_000000_add_media_photos.php'))->up();
        $actor = new UserProfile;
        $actor->setRawAttributes(['id' => (string) Str::uuid()], true);
        DB::table('user_profiles')->insert(['id' => $actor->id]);
        $actor->exists = true;
        $this->mediaRequest = Request::create('/synthetic-media');
        $this->mediaRequest->attributes->set('current_user', $actor);
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->andReturn((string) Str::uuid())->byDefault();
        $this->app->instance(AuditLogService::class, $audit);
        Storage::fake('media_private', ['visibility' => 'private']);
        $this->lifecycle = app(PhotoLifecycleService::class);
    }

    private function mediaParent(string $class): Model
    {
        $parent = new $class;
        $parent->setRawAttributes(['id' => (string) Str::uuid()], true);
        DB::table($parent->getTable())->insert(['id' => $parent->id]);
        $parent->exists = true;

        return $parent;
    }

    private function mediaAuthorization(): \Closure
    {
        return fn (Model $parent) => $parent->newQuery()->whereKey($parent->getKey())->lockForUpdate()->first()
            ?? throw new MediaLifecycleException('invalid_parent');
    }

    private function expireMedia(string $id): void
    {
        MediaPhoto::whereKey($id)->update(['lease_until' => now()->subMinute()]);
    }
}
