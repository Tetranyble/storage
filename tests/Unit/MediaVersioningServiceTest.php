<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaVersioningServiceTest extends PackageTestCase
{
    private MediaVersioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->service = $this->app->make(MediaVersioningService::class);
    }

    // ------------------------------------------------------------------
    // ensureVersionSeed
    // ------------------------------------------------------------------

    public function test_ensure_version_seed_sets_group_uuid_when_missing(): void
    {
        $media = $this->makeMedia();

        // version_group_uuid starts as null (no DB default)
        $this->assertNull($media->version_group_uuid);

        $groupUuid = $this->service->ensureVersionSeed($media);

        $this->assertNotEmpty($groupUuid);
        $media->refresh();
        $this->assertSame($groupUuid, $media->version_group_uuid);
        // version_number starts as 1 from DB default, seed keeps it at >= 1
        $this->assertGreaterThanOrEqual(1, $media->version_number);
    }

    public function test_ensure_version_seed_is_idempotent_when_already_set(): void
    {
        $groupUuid = (string) Str::uuid();
        $media = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 2]);

        $result = $this->service->ensureVersionSeed($media);

        $this->assertSame($groupUuid, $result);
        $media->refresh();
        $this->assertSame(2, $media->version_number);
    }

    // ------------------------------------------------------------------
    // prepareContext
    // ------------------------------------------------------------------

    public function test_prepare_context_returns_fresh_group_for_new_media(): void
    {
        [$groupUuid, $versionNumber, $previousVersionId] = $this->service->prepareContext(null);

        $this->assertNotEmpty($groupUuid);
        $this->assertSame(1, $versionNumber);
        $this->assertNull($previousVersionId);
    }

    public function test_prepare_context_bumps_version_and_marks_replaced_as_not_current(): void
    {
        $groupUuid = (string) Str::uuid();
        $existing = $this->makeMedia([
            'version_group_uuid' => $groupUuid,
            'version_number'     => 1,
            'current'            => true,
        ]);

        [$returnedGroup, $versionNumber, $previousId] = $this->service->prepareContext($existing);

        $this->assertSame($groupUuid, $returnedGroup);
        $this->assertSame(2, $versionNumber);
        $this->assertSame($existing->id, $previousId);

        $existing->refresh();
        $this->assertFalse((bool) $existing->current);
    }

    // ------------------------------------------------------------------
    // applyContext
    // ------------------------------------------------------------------

    public function test_apply_context_writes_version_fields_onto_media(): void
    {
        $prev = $this->makeMedia();  // real media ID for FK constraint
        $media = $this->makeMedia();
        $groupUuid = (string) Str::uuid();

        $this->service->applyContext($media, [$groupUuid, 3, $prev->id]);

        $media->refresh();
        $this->assertTrue((bool) $media->current);
        $this->assertSame($groupUuid, $media->version_group_uuid);
        $this->assertSame(3, $media->version_number);
        $this->assertSame($prev->id, $media->previous_version_id);
    }

    public function test_apply_context_can_mark_as_not_current(): void
    {
        $media = $this->makeMedia();

        $this->service->applyContext($media, [(string) Str::uuid(), 1, null], isCurrent: false);

        $media->refresh();
        $this->assertFalse((bool) $media->current);
    }

    // ------------------------------------------------------------------
    // versions
    // ------------------------------------------------------------------

    public function test_versions_returns_all_versions_newest_first(): void
    {
        $groupUuid = (string) Str::uuid();

        $v1 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 1, 'current' => false]);
        $v2 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 2, 'current' => true]);
        $v3 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 3, 'current' => true]);

        $this->service->applyContext($v1, [$groupUuid, 1, null], isCurrent: false);
        $this->service->applyContext($v2, [$groupUuid, 2, $v1->id], isCurrent: false);
        $this->service->applyContext($v3, [$groupUuid, 3, $v2->id]);

        $versions = $this->service->versions($v3);

        $this->assertCount(3, $versions);
        $this->assertSame(3, $versions->first()->version_number);
        $this->assertSame(1, $versions->last()->version_number);
    }

    // ------------------------------------------------------------------
    // currentVersion
    // ------------------------------------------------------------------

    public function test_current_version_returns_the_marked_current_media(): void
    {
        $groupUuid = (string) Str::uuid();
        $v1 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 1]);
        $v2 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 2]);

        $this->service->applyContext($v1, [$groupUuid, 1, null], isCurrent: false);
        $this->service->applyContext($v2, [$groupUuid, 2, $v1->id]);

        $current = $this->service->currentVersion($v1);

        $this->assertNotNull($current);
        $this->assertSame($v2->id, $current->id);
    }

    // ------------------------------------------------------------------
    // deleteVersion
    // ------------------------------------------------------------------

    public function test_delete_version_removes_non_current_version(): void
    {
        $workspace = Workspace::create(['name' => 'Acme']);
        $groupUuid = (string) Str::uuid();

        $v1 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 1, 'workspace_id' => $workspace->id, 'current' => false]);
        $v2 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 2, 'workspace_id' => $workspace->id, 'current' => true]);

        $user = $this->makeUser($workspace);

        $this->service->deleteVersion($workspace, $v1, $user);

        $this->assertNull(Media::withTrashed()->find($v1->id));
    }

    public function test_delete_version_throws_when_deleting_current_version(): void
    {
        $workspace = Workspace::create(['name' => 'Acme']);
        $groupUuid = (string) Str::uuid();

        $v1 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 1, 'workspace_id' => $workspace->id, 'current' => false]);
        $v2 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 2, 'workspace_id' => $workspace->id, 'current' => true]);

        $user = $this->makeUser($workspace);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the current version');

        $this->service->deleteVersion($workspace, $v2, $user);
    }

    public function test_delete_version_throws_when_only_one_version_exists(): void
    {
        $workspace = Workspace::create(['name' => 'Acme']);
        $groupUuid = (string) Str::uuid();

        $v1 = $this->makeMedia(['version_group_uuid' => $groupUuid, 'version_number' => 1, 'workspace_id' => $workspace->id, 'current' => false]);

        $user = $this->makeUser($workspace);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the only version');

        $this->service->deleteVersion($workspace, $v1, $user);
    }

    public function test_version_fields_not_mass_assignable_via_create(): void
    {
        $media = Media::create([
            'version_group_uuid'  => 'should-not-be-set',
            'version_number'      => 99,
            'current'             => true,
            'previous_version_id' => 1,
            'path'                => 'test/file.txt',
            'disk'                => Disk::PRIVATE,
            'use'                 => MediaPurpose::GENERAL,
            'status'              => MediaStatus::READY,
        ]);

        $fresh = $media->fresh();

        // version_group_uuid has no DB default — must remain null
        $this->assertNull($fresh->version_group_uuid);
        // version_number has DB default(1) — the value 99 was silently dropped by fillable guard
        $this->assertNotSame(99, $fresh->version_number);
        // current was NOT set via mass-assign; DB has no default so stays falsy
        $this->assertFalse((bool) $fresh->current);
        // previous_version_id: FK constraint means we can't even store 1 (no such media)
        // so it stays null
        $this->assertNull($fresh->previous_version_id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeMedia(array $attrs = []): Media
    {
        $defaults = [
            'path'   => 'test/'.Str::random(8).'.txt',
            'disk'   => Disk::PRIVATE,
            'use'    => MediaPurpose::GENERAL,
            'status' => MediaStatus::READY,
        ];

        $media = new Media(array_merge($defaults, $attrs));
        $media->save();

        // Directly force-fill version fields passed in attrs (bypassing fillable)
        $versionFields = array_intersect_key($attrs, array_flip([
            'current', 'version_group_uuid', 'version_number', 'previous_version_id',
        ]));
        if ($versionFields) {
            $media->forceFill($versionFields)->save();
        }

        return $media->fresh();
    }

    private function makeUser(Workspace $workspace): \Tetranyble\Storage\Models\User
    {
        return \Tetranyble\Storage\Models\User::create([
            'name'      => 'Actor',
            'email'     => 'actor'.Str::random(4).'@example.com',
            'password'  => bcrypt('secret'),
            'workspace_id' => $workspace->id,
        ]);
    }
}
