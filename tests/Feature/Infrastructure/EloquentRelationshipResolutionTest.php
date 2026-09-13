<?php

namespace Tetranyble\Storage\Tests\Feature\Infrastructure;

use Illuminate\Database\Eloquent\Relations\Relation;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Comment;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\ResourceStar;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSessionChunk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class EloquentRelationshipResolutionTest extends PackageTestCase
{
    public function test_modularized_model_relationships_resolve_the_intended_related_classes(): void
    {
        $this->assertRelated(new Workspace, 'connectedDrives', ConnectedDrive::class);

        $this->assertRelated(new UploadSession, 'folder', Folder::class);
        $this->assertRelated(new UploadSession, 'media', Media::class);
        $this->assertRelated(new UploadSession, 'chunks', UploadSessionChunk::class);

        $this->assertRelated(new DirectUploadSession, 'folder', Folder::class);
        $this->assertRelated(new DirectUploadSession, 'media', Media::class);

        $this->assertRelated(new MediaDerivative, 'media', Media::class);

        $this->assertRelated(new Media, 'folder', Folder::class);
        $this->assertRelated(new Media, 'shares', MediaShare::class);
        $this->assertRelated(new Media, 'collaborators', CollaboratorGrant::class);
        $this->assertRelated(new Media, 'activities', Activity::class);
        $this->assertRelated(new Media, 'stars', ResourceStar::class);
        $this->assertRelated(new Media, 'comments', Comment::class);
        $this->assertRelated(new Media, 'derivatives', MediaDerivative::class);

        $this->assertRelated(new Folder, 'media', Media::class);
        $this->assertRelated(new Folder, 'shares', MediaShare::class);
        $this->assertRelated(new Folder, 'collaborators', CollaboratorGrant::class);
        $this->assertRelated(new Folder, 'activities', Activity::class);
        $this->assertRelated(new Folder, 'stars', ResourceStar::class);
        $this->assertRelated(new Folder, 'comments', Comment::class);
    }

    /** @param class-string $expected */
    private function assertRelated(object $model, string $relationship, string $expected): void
    {
        $relation = $model->{$relationship}();

        $this->assertInstanceOf(Relation::class, $relation, $model::class.'::'.$relationship.'() must return an Eloquent relation.');
        $this->assertSame($expected, $relation->getRelated()::class, $model::class.'::'.$relationship.'() resolves the wrong model.');
    }
}
