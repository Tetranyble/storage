<?php

namespace Tetranyble\Storage\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;

final class LargeWorkspaceFixture
{
    /** @return array{workspace:Workspace,owner:User,viewer:User,root:Folder} */
    public function seed(?int $folderCount = null, ?int $mediaCount = null, ?int $grantCount = null): array
    {
        $folderCount ??= max(1, (int) config('tetranyble-storage.queries.benchmark.folders', 500));
        $mediaCount ??= max(1, (int) config('tetranyble-storage.queries.benchmark.media', 5000));
        $grantCount ??= max(0, (int) config('tetranyble-storage.queries.benchmark.grants', 500));

        $workspace = Workspace::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Benchmark Workspace']);
        $owner = User::query()->create(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Benchmark Owner']);
        $viewer = User::query()->create(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Benchmark Viewer']);
        $root = Folder::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'created_by' => null,
            'name' => 'Benchmark Root',
            'slug' => 'benchmark-root',
            'path' => 'root',
            'access_scope' => AccessScope::WORKSPACE,
            'is_root' => true,
        ]);

        $now = now();
        /** @var array<int,Folder> $createdFolders */
        $createdFolders = [0 => $root];
        for ($i = 1; $i <= $folderCount; $i++) {
            // Five-way branching produces a real multi-level tree without making
            // the opt-in fixture unnecessarily slow to build.
            $parentNumber = intdiv($i - 1, 5);
            $parent = $parentNumber === 0 ? $root : ($createdFolders[$parentNumber] ?? $root);
            $name = sprintf('benchmark-folder-%05d', $i);
            $folder = Folder::query()->create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'parent_id' => $parent->id,
                'created_by' => $owner->id,
                'name' => $name,
                'slug' => $name,
                'path' => $parent->path.'/'.$name,
                'access_scope' => $i % 10 === 0 ? AccessScope::RESTRICTED : AccessScope::WORKSPACE,
                'is_root' => false,
                'is_archived' => false,
            ]);
            $createdFolders[$i] = $folder;
        }

        $folderIds = collect($createdFolders)
            ->except(0)
            ->pluck('id')
            ->values()
            ->all();

        $rows = [];
        for ($i = 1; $i <= $mediaCount; $i++) {
            $folderId = $folderIds[($i - 1) % count($folderIds)] ?? $root->id;
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'folder_id' => $folderId,
                'disk' => Disk::PRIVATE->value,
                'path' => sprintf('benchmark/%d/report-%06d.pdf', $workspace->id, $i),
                'original_name' => sprintf('benchmark-report-%06d.pdf', $i),
                'mime_type' => 'application/pdf',
                'size' => 1024 + $i,
                'use' => MediaPurpose::GENERAL->value,
                'current' => true,
                'version_number' => 1,
                'uploaded_by' => $owner->id,
                'access_scope' => $i % 10 === 0 ? AccessScope::RESTRICTED->value : AccessScope::WORKSPACE->value,
                'status' => 'PENDING',
                'virus_scan_status' => 'pending',
                'processing_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Media::query()->insert($chunk);
        }

        if ($grantCount > 0) {
            $grants = [];
            $folderMorph = (new Folder())->getMorphClass();
            $mediaMorph = (new Media())->getMorphClass();
            $folderGrantBudget = intdiv($grantCount, 2);

            $restrictedFolders = Folder::query()
                ->where('workspace_id', $workspace->id)
                ->where('access_scope', AccessScope::RESTRICTED->value)
                ->limit($folderGrantBudget)
                ->pluck('id');
            foreach ($restrictedFolders as $folderId) {
                $grants[] = [
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'collaboratable_type' => $folderMorph,
                    'collaboratable_id' => $folderId,
                    'user_id' => $viewer->id,
                    'role' => CollaboratorRole::VIEWER->value,
                    'granted_by' => $owner->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $remainingGrantBudget = max(0, $grantCount - count($grants));
            $restrictedMedia = Media::query()
                ->where('workspace_id', $workspace->id)
                ->where('access_scope', AccessScope::RESTRICTED->value)
                ->limit($remainingGrantBudget)
                ->pluck('id');
            foreach ($restrictedMedia as $mediaId) {
                $grants[] = [
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'collaboratable_type' => $mediaMorph,
                    'collaboratable_id' => $mediaId,
                    'user_id' => $viewer->id,
                    'role' => CollaboratorRole::VIEWER->value,
                    'granted_by' => $owner->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($grants, 500) as $chunk) {
                if ($chunk !== []) {
                    DB::table('collaborator_grants')->insert($chunk);
                }
            }
        }

        return compact('workspace', 'owner', 'viewer', 'root');
    }
}
