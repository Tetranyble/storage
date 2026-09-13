<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Comment;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

class CommentService
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    public function addComment(
        Model $workspace,
        Media|Folder $resource,
        Model $actor,
        string $body,
        ?Comment $parentComment = null,
    ): Comment {
        $this->assertWorkspaceResource($workspace, $resource);
        $this->access->authorizeView($workspace, $resource, $actor);

        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Comment body cannot be empty.');
        }

        if ($parentComment) {
            if ((int) ($parentComment->workspace_id ?? 0) !== (int) $workspace->id) {
                throw new ResourceNotFoundException();
            }
            if ($parentComment->commentable_type !== $resource->getMorphClass()
                || (int) $parentComment->commentable_id !== (int) $resource->getKey()) {
                throw new RuntimeException('Parent comment does not belong to this resource.');
            }
        }

        return Comment::create([
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'parent_id' => $parentComment?->id,
            'commentable_type' => $resource->getMorphClass(),
            'commentable_id' => $resource->getKey(),
            'body' => $body,
        ]);
    }

    public function editComment(
        Model $workspace,
        Comment $comment,
        Model $actor,
        string $body,
    ): Comment {
        $this->assertCommentOwner($workspace, $comment, $actor);

        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Comment body cannot be empty.');
        }

        $comment->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        return $comment->refresh();
    }

    public function deleteComment(Model $workspace, Comment $comment, Model $actor): void
    {
        if ((int) ($comment->workspace_id ?? 0) !== (int) $workspace->id) {
            throw new ResourceNotFoundException();
        }

        $isOwner = (int) ($comment->user_id ?? 0) === (int) $actor->id;
        $resource = $comment->commentable;

        $canModerate = $resource instanceof Model
            && $this->access->canManagePermissions($workspace, $resource, $actor);

        if (! $isOwner && ! $canModerate) {
            $this->recordCommentDenial('delete', $workspace, $comment, $actor);
            throw new AccessDeniedException();
        }

        $comment->delete();
    }

    public function listComments(
        Model $workspace,
        Media|Folder $resource,
        ?Model $actor = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $this->assertWorkspaceResource($workspace, $resource);

        if ($actor) {
            $this->access->authorizeView($workspace, $resource, $actor);
        }

        $paginator = Comment::query()
            ->where('workspace_id', $workspace->id)
            ->where('commentable_type', $resource->getMorphClass())
            ->where('commentable_id', $resource->getKey())
            ->whereNull('parent_id')
            ->with(['author', 'replies.author'])
            ->orderBy('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'comments' => collect($paginator->items())
                ->map(fn (Comment $c) => $this->toDto($c, withReplies: true))
                ->values(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    private function toDto(Comment $comment, bool $withReplies = false): array
    {
        $dto = [
            'id' => $comment->id,
            'uuid' => $comment->uuid,
            'body' => $comment->body,
            'user_id' => $comment->user_id,
            'parent_id' => $comment->parent_id,
            'is_edited' => $comment->isEdited(),
            'edited_at' => optional($comment->edited_at)?->toIso8601String(),
            'created_at' => optional($comment->created_at)?->toIso8601String(),
        ];

        if ($withReplies && $comment->relationLoaded('replies')) {
            $dto['replies'] = $comment->replies
                ->map(fn (Comment $reply) => $this->toDto($reply))
                ->values();
        }

        return $dto;
    }

    private function assertCommentOwner(Model $workspace, Comment $comment, Model $actor): void
    {
        if ((int) ($comment->workspace_id ?? 0) !== (int) $workspace->id) {
            throw new ResourceNotFoundException();
        }

        if ((int) ($comment->user_id ?? 0) !== (int) $actor->id) {
            $this->recordCommentDenial('modify', $workspace, $comment, $actor);
            throw new AccessDeniedException();
        }
    }


    private function recordCommentDenial(string $action, Model $workspace, Comment $comment, Model $actor): void
    {
        $this->telemetry?->counter('access.denials', 1, ['action' => 'comment_'.$action, 'resource_type' => 'comment']);
        $this->telemetry?->event('access.denied', [
            'action' => 'comment_'.$action,
            'workspace_id' => (int) $workspace->getKey(),
            'resource_type' => 'comment',
            'resource_id' => $comment->getKey(),
            'user_id' => $actor->getKey(),
        ], TelemetryLevel::NOTICE);
    }

    private function assertWorkspaceResource(Model $workspace, Model $resource): void
    {
        if ((int) ($resource->workspace_id ?? 0) !== (int) $workspace->id) {
            throw new ResourceNotFoundException();
        }
    }
}
