<?php

namespace Tetranyble\Storage\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tetranyble\Storage\Contracts\Workspace;
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaStorageTransferService;
use Tetranyble\Storage\Models\ConnectedDrive;

class MediaTransferController extends StorageController
{
    public function __construct(
        Workspace $workspace,
        private readonly MediaStorageTransferService $mediaTransfers,
        private readonly ConnectedDriveService $connectedDrives,
    ) {
        parent::__construct($workspace);
    }

    public function copyMedia(Request $request, string $media): JsonResponse
    {
        $validated = $this->validateMediaTransfer($request);
        $workspace = $this->workspace($request);
        $copy = $this->mediaTransfers->copy(
            $workspace,
            $this->media($workspace, $media),
            isset($validated['destination_disk']) ? Disk::from($validated['destination_disk']) : null,
            $validated['destination_path'] ?? null,
            $this->actor($request),
        );

        return $this->success('Media copied.', ['media' => $this->mediaPayload($copy)], 201);
    }

    public function moveMedia(Request $request, string $media): JsonResponse
    {
        $validated = $this->validateMediaTransfer($request);
        $workspace = $this->workspace($request);
        $moved = $this->mediaTransfers->move(
            $workspace,
            $this->media($workspace, $media),
            isset($validated['destination_disk']) ? Disk::from($validated['destination_disk']) : null,
            $validated['destination_path'] ?? null,
            $this->actor($request),
        );

        return $this->success('Media moved.', ['media' => $this->mediaPayload($moved)]);
    }

    public function copyDriveFile(Request $request, string $drive): JsonResponse
    {
        $validated = $this->validateDriveTransfer($request);
        $workspace = $this->workspace($request);
        $result = $this->connectedDrives->copyFile(
            $workspace,
            $this->drive($workspace, $drive),
            $validated['file_id'],
            $this->drive($workspace, $validated['destination_drive']),
            $validated['destination_folder_id'] ?? 'root',
            $validated['name'] ?? null,
            $this->actor($request),
        );

        return $this->success('Connected-drive file copied.', ['file' => $result->toArray()], 201);
    }

    public function moveDriveFile(Request $request, string $drive): JsonResponse
    {
        $validated = $this->validateDriveTransfer($request);
        $workspace = $this->workspace($request);
        $result = $this->connectedDrives->moveFile(
            $workspace,
            $this->drive($workspace, $drive),
            $validated['file_id'],
            $this->drive($workspace, $validated['destination_drive']),
            $validated['destination_folder_id'] ?? 'root',
            $validated['name'] ?? null,
            $this->actor($request),
        );

        return $this->success('Connected-drive file moved.', ['file' => $result->toArray()]);
    }

    public function setDefaultDrive(Request $request, string $drive): JsonResponse
    {
        $workspace = $this->workspace($request);
        $selected = $this->drive($workspace, $drive);
        $this->connectedDrives->setDefault($workspace, $selected, $this->actor($request));

        return $this->success('Default media driver updated.', [
            'drive' => $this->drivePayload($selected->refresh()),
        ]);
    }

    private function validateMediaTransfer(Request $request): array
    {
        return $request->validate([
            'destination_disk' => ['nullable', Rule::enum(Disk::class)],
            'destination_path' => ['nullable', 'string', 'max:1024'],
        ]);
    }

    private function validateDriveTransfer(Request $request): array
    {
        return $request->validate([
            'file_id' => ['required', 'string', 'max:1024'],
            'destination_drive' => ['required', 'string', 'max:191'],
            'destination_folder_id' => ['nullable', 'string', 'max:1024'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function drive(Model $workspace, string|int $key): ConnectedDrive
    {
        return ConnectedDrive::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(fn ($query) => $query->whereKey($key)->orWhere('uuid', $key))
            ->firstOrFail();
    }

    private function drivePayload(ConnectedDrive $drive): array
    {
        return [
            'id' => $drive->getKey(),
            'uuid' => $drive->uuid,
            'name' => $drive->name,
            'provider' => $drive->provider->value,
            'is_default' => (bool) $drive->is_default,
            'status' => $drive->status->value,
        ];
    }
}
