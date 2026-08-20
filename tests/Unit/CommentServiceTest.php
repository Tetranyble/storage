<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\CommentService;
use Tetranyble\Storage\Models\Comment;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

class CommentServiceTest extends PackageTestCase
{
    private MockInterface $access;
    private CommentService $service;
    private Workspace $workspace;
    private User $user;
    private Folder $folder;
    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = Mockery::mock(ResourceAccessControl::class);
        // By default allow view and permission management
        $this->access->shouldReceive('authorizeView')->andReturn(null)->byDefault();
        $this->access->shouldReceive('canManagePermissions')->andReturn(false)->byDefault();

        $this->service = new CommentService($this->access);

        $this->workspace = Workspace::create(['name' => 'Corp', 'uuid' => Str::uuid()]);
        $this->user   = User::create(['name' => 'Bob', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);

        $this->folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name'      => 'Root',
            'slug'      => 'root',
            'path'      => '/',
            'uuid'      => Str::uuid(),
        ]);

        $this->media = Media::create([
            'workspace_id' => $this->workspace->id,
            'folder_id' => $this->folder->id,
            'uuid'      => Str::uuid(),
            'disk'      => 'public',
            'path'      => 'doc.pdf',
        ]);
    }

    public function test_add_comment_creates_record_on_folder(): void
    {
        $comment = $this->service->addComment($this->workspace, $this->folder, $this->user, 'Great folder!');

        $this->assertInstanceOf(Comment::class, $comment);
        $this->assertSame('Great folder!', $comment->body);
        $this->assertSame($this->user->id, $comment->user_id);
        $this->assertSame($this->workspace->id, $comment->workspace_id);
        $this->assertNull($comment->parent_id);
        $this->assertDatabaseHas('storage_comments', ['body' => 'Great folder!']);
    }

    public function test_add_comment_creates_record_on_media(): void
    {
        $comment = $this->service->addComment($this->workspace, $this->media, $this->user, 'Nice file');

        $this->assertSame('Nice file', $comment->body);
        $this->assertSame((string) Media::class, $comment->commentable_type);
    }

    public function test_add_reply_links_parent(): void
    {
        $parent = $this->service->addComment($this->workspace, $this->folder, $this->user, 'Parent');
        $reply  = $this->service->addComment($this->workspace, $this->folder, $this->user, 'Reply', $parent);

        $this->assertSame($parent->id, $reply->parent_id);
    }

    public function test_add_comment_rejects_empty_body(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty/i');

        $this->service->addComment($this->workspace, $this->folder, $this->user, '   ');
    }

    public function test_add_comment_rejects_wrong_workspace_resource(): void
    {
        $other   = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $folder2 = Folder::create(['workspace_id' => $other->id, 'name' => 'X', 'slug' => 'x', 'path' => '/x', 'uuid' => Str::uuid()]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->addComment($this->workspace, $folder2, $this->user, 'Hello');
    }

    public function test_edit_comment_updates_body_and_edited_at(): void
    {
        $comment = Comment::create([
            'workspace_id'        => $this->workspace->id,
            'user_id'          => $this->user->id,
            'commentable_type' => Folder::class,
            'commentable_id'   => $this->folder->id,
            'body'             => 'Original',
            'uuid'             => Str::uuid(),
        ]);

        $updated = $this->service->editComment($this->workspace, $comment, $this->user, 'Updated');

        $this->assertSame('Updated', $updated->body);
        $this->assertNotNull($updated->edited_at);
        $this->assertTrue($updated->isEdited());
    }

    public function test_edit_comment_rejects_non_owner(): void
    {
        $other   = User::create(['name' => 'Carol', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);
        $comment = Comment::create([
            'workspace_id'        => $this->workspace->id,
            'user_id'          => $this->user->id,
            'commentable_type' => Folder::class,
            'commentable_id'   => $this->folder->id,
            'body'             => 'Mine',
            'uuid'             => Str::uuid(),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->editComment($this->workspace, $comment, $other, 'Hijacked');
    }

    public function test_delete_comment_soft_deletes_by_owner(): void
    {
        $comment = Comment::create([
            'workspace_id'        => $this->workspace->id,
            'user_id'          => $this->user->id,
            'commentable_type' => Folder::class,
            'commentable_id'   => $this->folder->id,
            'body'             => 'Delete me',
            'uuid'             => Str::uuid(),
        ]);

        $this->service->deleteComment($this->workspace, $comment, $this->user);

        $this->assertSoftDeleted('storage_comments', ['id' => $comment->id]);
    }

    public function test_delete_comment_allowed_for_moderator(): void
    {
        $this->access->shouldReceive('canManagePermissions')->andReturn(true);

        $other   = User::create(['name' => 'Mod', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);
        $comment = Comment::create([
            'workspace_id'        => $this->workspace->id,
            'user_id'          => $this->user->id,
            'commentable_type' => Folder::class,
            'commentable_id'   => $this->folder->id,
            'body'             => 'Offensive',
            'uuid'             => Str::uuid(),
        ]);

        $this->service->deleteComment($this->workspace, $comment, $other);

        $this->assertSoftDeleted('storage_comments', ['id' => $comment->id]);
    }

    public function test_list_comments_returns_paginated_top_level_only(): void
    {
        $parent = $this->service->addComment($this->workspace, $this->folder, $this->user, 'Top-level');
        $this->service->addComment($this->workspace, $this->folder, $this->user, 'Reply', $parent);
        $this->service->addComment($this->workspace, $this->folder, $this->user, 'Another top');

        $result = $this->service->listComments($this->workspace, $this->folder);

        $this->assertCount(2, $result['comments']); // only top-level
        $this->assertArrayHasKey('pagination', $result);
        $this->assertSame(2, $result['pagination']['total']);

        // Replies are nested inside the first comment
        $firstComment = $result['comments']->first();
        $this->assertArrayHasKey('replies', $firstComment);
        $this->assertCount(1, $firstComment['replies']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
