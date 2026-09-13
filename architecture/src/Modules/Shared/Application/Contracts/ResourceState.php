<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Shared\Application\Contracts;

/**
 * Framework-neutral access to opaque host/package resource handles.
 *
 * Application use cases never need to know whether a handle is an Eloquent
 * model. The adapter owns identifier/attribute/mutation semantics.
 */
interface ResourceState
{
    public function key(object $resource): int|string|null;
    public function attribute(object $resource, string $name, mixed $default = null): mixed;
    public function morphClass(object $resource): string;
    public function update(object $resource, array $attributes): object;
    public function refresh(object $resource): object;
    public function delete(object $resource): void;
}
