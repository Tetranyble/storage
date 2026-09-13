# Step 7 — Cloud Provider Strategy + Registry

Cloud-drive provider construction is no longer owned by `ConnectedDriveService`.

## Boundary

`ConnectedDriveService` coordinates connection persistence, workspace ownership, transfer workflows, import/export, OAuth refresh and default-drive management. It does **not** construct Google, OneDrive, Dropbox, S3, Azure, GCS, Cloudinary or Local adapters and it does not validate provider-specific credential shapes.

Those responsibilities belong to `CloudProviderStrategy` implementations registered in `CloudProviderRegistry`.

Each strategy owns:

- its `CloudProvider` identity;
- optional Composer/package requirements;
- provider-specific adapter construction; and
- validation/probing for credential-based connections.

The default registry contains eight strategies: Google Drive, OneDrive, Dropbox, S3, Azure Blob, Google Cloud Storage, Cloudinary and Local.

## Connection flow

OAuth providers continue through `connectOAuth()`. Non-OAuth providers can use the provider-neutral:

```php
$drives->connectCredentials($workspace, CloudProvider::S3, $credentials, 'Archive');
```

The existing `connectS3()`, `connectAzureBlob()`, `connectGcs()`, `connectCloudinary()` and `connectLocal()` methods remain convenience compatibility wrappers over that generic path.

S3 preserves its existing pre-persistence remote probe. Azure, GCS and Cloudinary preserve validation-only connection behavior.

## Extension

The registry is a singleton in the Laravel composition root and exposes `register()` deliberately. A host can replace a strategy for an existing provider without modifying `ConnectedDriveService`:

```php
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderRegistry;

app(CloudProviderRegistry::class)->register(new CompanyS3Provider());
```

Adding a new built-in provider requires a provider enum case/metadata and strategy registration in the composition factory, but no central orchestration changes, provider dispatch `match`, adapter factory method, OAuth-service branch, or dependency-guard table change. Optional package requirements travel with the strategy.

## Enforcement

`composer run architecture:providers` rejects provider adapter construction or provider-specific factory methods in `ConnectedDriveService`, verifies the eight default strategies, verifies the registry extension point and checks Laravel composition.
