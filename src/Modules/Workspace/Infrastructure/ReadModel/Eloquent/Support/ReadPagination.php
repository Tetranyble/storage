<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Cursor;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;

final class ReadPagination
{
    public function bounded(int $perPage): int
    {
        $max = max(1, (int) config('tetranyble-storage.queries.max_per_page', 200));

        return min(max(1, $perPage), $max);
    }

    public function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null || trim($encoded) === '') {
            return null;
        }
        try {
            $cursor = Cursor::fromEncoded($encoded);
        } catch (\Throwable $exception) {
            throw new InvalidStorageOperationException('The pagination cursor is invalid.', previous: $exception);
        }
        if (! $cursor) {
            throw new InvalidStorageOperationException('The pagination cursor is invalid.');
        }

        return $cursor;
    }

    public function lengthAware(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(), 'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
        ];
    }

    public function mapLengthAware(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return ['data' => collect($paginator->items())->map($mapper)->values(), 'pagination' => $this->lengthAware($paginator)];
    }

    public function mapCursor(CursorPaginator $paginator, callable $mapper): array
    {
        return ['data' => collect($paginator->items())->map($mapper)->values(), 'pagination' => $this->cursorMeta($paginator)];
    }

    public function cursorMeta(CursorPaginator $paginator): array
    {
        return [
            'per_page' => $paginator->perPage(), 'next_cursor' => $paginator->nextCursor()?->encode(),
            'previous_cursor' => $paginator->previousCursor()?->encode(), 'has_more' => $paginator->hasMorePages(),
        ];
    }

    public function emptyCursorPage(int $perPage): array
    {
        return ['data' => collect(), 'pagination' => ['per_page' => $perPage, 'next_cursor' => null, 'previous_cursor' => null, 'has_more' => false]];
    }
}
