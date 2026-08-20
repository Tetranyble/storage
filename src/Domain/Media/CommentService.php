<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Models\Comment;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CommentService
{
    public function __construct(
        private readonly ResourceAccessControl $access,
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
                abort(404);
            }
            if ($parentComment->commentable_type !== $resource::class
                || (int) $parentComment->commentable_id !== (int) $resource->getKey()) {
                throw new RuntimeException('Parent comment does not belong to this resource.');
            }
        }

        return Comment::create([
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'parent_id' => $parentComment?->id,
            'commentable_type' => $resource::class,
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
            abort(404);
        }

        $isOwner = (int) ($comment->user_id ?? 0) === (int) $actor->id;
        $resource = $comment->commentable;

        $canModerate = $resource instanceof Model
            && $this->access->canManagePermissions($workspace, $resource, $actor);

        if (! $isOwner && ! $canModerate) {
            abort(403);
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
            ->where('commentable_type', $resource::class)
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
            abort(404);
        }

        if ((int) ($comment->user_id ?? 0) !== (int) $actor->id) {
            abort(403);
        }
    }

    private function assertWorkspaceResource(Model $workspace, Model $resource): void
    {
        if ((int) ($resource->workspace_id ?? 0) !== (int) $workspace->id) {
            abort(404);
        }
    }
}
