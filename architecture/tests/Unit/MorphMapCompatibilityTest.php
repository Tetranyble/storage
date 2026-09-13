<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadOptionsCodec;
use Tetranyble\Storage\Modules\Upload\Infrastructure\ResumableUploadService;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Access\AccessControlService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Tests\Fixtures\Models\DummyMediableModel;
use Tetranyble\Storage\Tests\PackageTestCase;

class MorphMapCompatibilityTest extends PackageTestCase
{
    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        parent::tearDown();
    }

    public function test_polymorphic_writes_use_morph_aliases(): void
    {
        Relation::morphMap([
            'storage-media' => Media::class,
            'test-mediable' => DummyMediableModel::class,
        ], false);

        $workspace = Workspace::create(['name' => 'Morphs', 'uuid' => Str::uuid()]);
        $user = User::create(['workspace_id' => $workspace->id, 'name' => 'Alice', 'uuid' => Str::uuid()]);
        $model = DummyMediableModel::create(['workspace_id' => $workspace->id]);
        $media = Media::create(['workspace_id' => $workspace->id, 'path' => 'morph.txt']);

        $media->associate($model);
        $this->assertSame('test-mediable', $media->fresh()->mediable_type);

        $access = app(AccessControlService::class);
        $access->grant($workspace, $media, $user, CollaboratorRole::VIEWER);
        $this->assertSame('storage-media', CollaboratorGrant::query()->latest('id')->value('collaboratable_type'));
        $this->assertSame(CollaboratorRole::VIEWER, $access->effectiveRole($workspace, $media, $user));

        $share = app(MediaShareService::class)->createForMedia($workspace, $media);
        $this->assertSame('storage-media', MediaShare::query()->latest('id')->value('shareable_type'));
        $this->assertInstanceOf(Media::class, $share->fresh()->shareable);
    }

    public function test_morph_aliases_are_resolved_when_reconstructing_upload_context(): void
    {
        Relation::morphMap([
            'storage-media' => Media::class,
            'test-mediable' => DummyMediableModel::class,
        ], false);

        $workspace = Workspace::create(['name' => 'Morph context', 'uuid' => Str::uuid()]);
        $model = DummyMediableModel::create(['workspace_id' => $workspace->id]);
        $media = Media::create(['workspace_id' => $workspace->id, 'path' => 'morph.txt']);
        $media->associate($model);

        $versionOptions = new ReflectionMethod(MediaService::class, 'versionUploadOptions');
        $versionOptions->setAccessible(true);
        $resolved = $versionOptions->invoke(app(MediaService::class), $media->fresh(), null);
        $this->assertSame($model->getKey(), $resolved->model?->getKey());
        $this->assertInstanceOf(DummyMediableModel::class, $resolved->model);

        $resumable = app(ResumableUploadService::class);
        $session = $resumable->startSession(new UploadSessionOptions(
            identifier: 'morph-session',
            upload: MediaUploadOptions::forModel($model),
            totalChunks: 1,
            totalSize: 10,
        ));
        $this->assertSame('test-mediable', $session->upload_options['model_type']);

        $hydrated = app(ResumableUploadOptionsCodec::class)->hydrate($session->upload_options);
        $this->assertInstanceOf(DummyMediableModel::class, $hydrated->model);
        $this->assertSame($model->getKey(), $hydrated->model?->getKey());
    }
}
