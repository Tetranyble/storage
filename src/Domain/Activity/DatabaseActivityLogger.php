<?php

namespace Tetranyble\Storage\Domain\Activity;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DatabaseActivityLogger implements ActivityLogger
{
    public function log(
        Model $subject,
        string $type,
        string $description,
        ?Model $actor = null,
        array $meta = [],
        array $changes = [],
        ?int $workspaceId = null,
    ): Activity {
        return Activity::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $actor?->id,
            'workspace_id' => $workspaceId ?? $this->resolveWorkspaceId($subject, $actor),
            'subject_id' => $subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_uuid' => (string) ($subject->getAttribute('uuid') ?: Str::uuid()),
            'type' => $type,
            'description' => $description,
            'changes' => $this->normalizeChanges($changes),
            'meta' => $this->normalizeMeta(array_merge([
                'at' => now()->toIso8601String(),
            ], $meta)),
        ]);
    }

    private function normalizeChanges(array $changes): array
    {
        $before = $changes['before'] ?? [];
        $after = $changes['after'] ?? [];

        return [
            'before' => is_array($before) ? $before : [],
            'after' => is_array($after) ? $after : [],
        ];
    }

    private function normalizeMeta(array $meta): array
    {
        return collect($meta)
            ->map(function ($value) {
                if ($value instanceof \BackedEnum) {
                    return $value->value;
                }

                if ($value instanceof \DateTimeInterface) {
                    return $value->format(\DateTimeInterface::ATOM);
                }

                return $value;
            })
            ->all();
    }

    private function resolveWorkspaceId(Model $subject, ?Model $actor): ?int
    {
        $subjectWorkspaceId = $subject->getAttribute(StorageConfig::resourceWorkspaceForeignKey());
        if ($subjectWorkspaceId !== null) {
            return (int) $subjectWorkspaceId;
        }

        return StorageConfig::actorWorkspaceId($actor);
    }
}
