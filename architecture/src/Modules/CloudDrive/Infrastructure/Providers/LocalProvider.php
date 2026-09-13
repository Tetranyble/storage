<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\LocalAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class LocalProvider implements CloudProviderStrategy
{
    public function provider(): CloudProvider
    {
        return CloudProvider::LOCAL;
    }

    public function packageRequirements(): array
    {
        return [];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        return new LocalAdapter($drive->credentials['disk'] ?? 'local');
    }

    public function prepareCredentials(array $credentials): void {}
}
