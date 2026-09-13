<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

interface CloudProviderStrategy
{
    public function provider(): CloudProvider;

    /** @return array<string, class-string> package => probe class */
    public function packageRequirements(): array;

    public function adapter(ConnectedDrive $drive): CloudAdapter;

    /**
     * Validate provider credentials before a credential-based connection is persisted.
     * Strategies may also perform a lightweight remote probe when that is part of the
     * provider's established connection contract.
     *
     * @param array<string, mixed> $credentials
     */
    public function prepareCredentials(array $credentials): void;
}
