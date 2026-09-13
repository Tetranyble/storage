<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Versioning\Application\Contracts;

interface MediaVersioning
{
    public function versions(object $media): iterable;

    public function currentVersion(object $media): ?object;

    public function activity(object $media): iterable;

    public function deleteVersion(object $workspace, object $version, object $actor): void;

    public function prepareContext(?object $replacedMedia, bool $supersede = true): array;

    public function applyContext(object $media, array $context, bool $isCurrent = true): void;

    public function ensureVersionSeed(object $media): string;
}
