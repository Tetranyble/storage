<?php

namespace Tetranyble\Storage\Enums;

enum CollaboratorRole: string
{
    case VIEWER = 'viewer';
    case COMMENTER = 'commenter';
    case EDITOR = 'editor';
    case OWNER = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::VIEWER => 1,
            self::COMMENTER => 2,
            self::EDITOR => 3,
            self::OWNER => 4,
        };
    }

    public function allowsView(): bool
    {
        return true;
    }

    public function allowsComment(): bool
    {
        return $this->rank() >= self::COMMENTER->rank();
    }

    public function allowsEdit(): bool
    {
        return $this->rank() >= self::EDITOR->rank();
    }

    public function allowsManagePermissions(): bool
    {
        return $this === self::OWNER;
    }

    public static function highest(?self $left, ?self $right): ?self
    {
        if (! $left) {
            return $right;
        }

        if (! $right) {
            return $left;
        }

        return $left->rank() >= $right->rank() ? $left : $right;
    }
}
