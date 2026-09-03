# Connected Drives

Tetranyble Storage exposes Google Drive, Microsoft OneDrive, Dropbox, Amazon S3, Azure Blob Storage, Google Cloud Storage, Cloudinary, and Laravel disks through one API. A connected drive belongs to a workspace, and the first drive connected to that workspace becomes its default.

This guide covers provider installation, OAuth setup, credentials, common operations, and migration from the legacy OneDrive and Azure dependencies.

- [Provider dependencies](#provider-dependencies)
- [Shared setup](#shared-setup)
- [Microsoft OneDrive](#microsoft-onedrive)
- [Azure Blob Storage](#azure-blob-storage)
- [Common operations](#common-operations)
- [Security guidance](#security-guidance)
- [Troubleshooting](#troubleshooting)
- [Upgrading from the legacy adapters](#upgrading-from-the-legacy-adapters)

## Provider dependencies

The package keeps provider SDKs optional. Install only the integrations used by the host application.

| Provider | Composer package |
|---|---|
| Local Laravel disk | None |
| Microsoft OneDrive | None; uses Laravel's HTTP client |
| Google Drive | `google/apiclient:^2.15` |
| Dropbox | `spatie/dropbox-api:^1.0` |
| Amazon S3 and compatible services | `league/flysystem-aws-s3-v3:^3.28` |
| Azure Blob Storage | `azure-oss/storage-blob-flysystem:^2.2` |
| Google Cloud Storage | `league/flysystem-google-cloud-storage:^3.28` |
| Cloudinary | `cloudinary/cloudinary_php:^2.0` |

For example, an application using OneDrive and Azure only needs the Azure package:

```bash
composer require azure-oss/storage-blob-flysystem:^2.2
```

OneDrive does not require `microsoft/microsoft-graph`. It calls the Microsoft Graph v1.0 REST API through `illuminate/http`, which is already a core dependency.

## Shared setup

Publish the package configuration and migrations:

```bash
php artisan vendor:publish --tag=tetranyble-storage-config
php artisan vendor:publish --tag=tetranyble-storage-migrations
php artisan migrate
```

Resolve the services from Laravel's container:

```php
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;

$oauth = app(OAuthService::class);
$drives = app(ConnectedDriveService::class);
```

OAuth credentials are configured in `config/storage.php`:

```php
'cloud_drives' => [
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],
    'onedrive' => [
        'client_id' => env('ONEDRIVE_CLIENT_ID'),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
        'redirect_uri' => env('ONEDRIVE_REDIRECT_URI'),
        'tenant_id' => env('ONEDRIVE_TENANT_ID', 'common'),
    ],
],
```

The corresponding environment values are:

```dotenv
GOOGLE_DRIVE_CLIENT_ID=
GOOGLE_DRIVE_CLIENT_SECRET=
GOOGLE_DRIVE_REDIRECT_URI=https://app.example.com/storage/oauth/google/callback

ONEDRIVE_CLIENT_ID=
ONEDRIVE_CLIENT_SECRET=
ONEDRIVE_REDIRECT_URI=https://app.example.com/storage/oauth/onedrive/callback
ONEDRIVE_TENANT_ID=common
```

The redirect URI must exactly match the URI registered with the provider.

## Microsoft OneDrive

### OAuth permissions

Register an application with the Microsoft identity platform and configure the callback URI used by the host application. The package requests these scopes:

- `https://graph.microsoft.com/Files.ReadWrite.All`
- `offline_access`

`offline_access` lets Microsoft return a refresh token. The package refreshes access tokens shortly before expiry and persists rotated refresh tokens when Microsoft returns one.

`ONEDRIVE_TENANT_ID` defaults to `common`. Set a tenant ID when the application must accept accounts from one Microsoft Entra tenant only.

### Authorization redirect

Generate a state value, keep it in the session, and pass it to `buildAuthUrl()`:

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Enums\CloudProvider;

public function redirectToOneDrive(OAuthService $oauth): RedirectResponse
{
    $state = Str::random(40);
    session(['onedrive_oauth_state' => $state]);

    return redirect()->away(
        $oauth->buildAuthUrl(CloudProvider::ONEDRIVE, $state),
    );
}
```

### OAuth callback

Validate state before exchanging the authorization code:

```php
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Enums\CloudProvider;

public function oneDriveCallback(
    Request $request,
    OAuthService $oauth,
    ConnectedDriveService $drives,
): Response {
    $expectedState = (string) $request->session()->pull('onedrive_oauth_state');
    $actualState = (string) $request->query('state');

    abort_unless(
        $expectedState !== '' && hash_equals($expectedState, $actualState),
        403,
        'Invalid OAuth state.',
    );

    $tokenData = $oauth->exchangeCode(
        CloudProvider::ONEDRIVE,
        (string) $request->query('code'),
    );

    $drive = $drives->connectOAuth(
        workspace: $request->user()->workspace,
        provider: CloudProvider::ONEDRIVE,
        tokenData: $tokenData,
        name: 'Work OneDrive',
    );

    return response()->json($drive);
}
```

Access tokens, refresh tokens, and provider credentials are encrypted by the `ConnectedDrive` model and hidden during serialization. The application encryption key must remain stable or stored credentials will become unreadable.

### Selecting another drive

OneDrive uses `/me/drive` by default. To address a known drive instead, store a Graph drive path on the connection:

```php
$drive->forceFill([
    'credentials' => [
        'drive_path' => '/drives/'.$microsoftDriveId,
    ],
])->save();
```

Use a drive ID obtained through an authorized Microsoft Graph request. Do not place an arbitrary URL in `drive_path`.

### OneDrive behavior

- Folder listings follow Microsoft Graph pagination automatically.
- Uploads use the Graph simple-content endpoint and request rename-on-conflict behavior.
- Downloads first request a pre-authenticated download URL, then fetch that URL without forwarding the Graph bearer token.
- Missing items are ignored during deletion, making repeated deletes safe.
- Same-drive moves use a Graph metadata update.
- Same-drive copies are synchronous and therefore download and upload the content. Queue large copy operations in the host application.

## Azure Blob Storage

Azure support uses `azure-oss/storage-blob-flysystem`. Install it in the host application:

```bash
composer require azure-oss/storage-blob-flysystem:^2.2
```

The package connects to an existing container. Supply either a complete Azure Storage connection string or an account name and account key.

### Connection string

```php
$drive = $drives->connectAzureBlob(
    workspace: $workspace,
    credentials: [
        'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
        'container' => 'workspace-files',
    ],
    name: 'Azure Files',
);
```

Example environment variables:

```dotenv
AZURE_STORAGE_CONNECTION_STRING=
AZURE_STORAGE_CONTAINER=workspace-files
```

### Account name and key

```php
$drive = $drives->connectAzureBlob(
    workspace: $workspace,
    credentials: [
        'account_name' => env('AZURE_STORAGE_ACCOUNT'),
        'account_key' => env('AZURE_STORAGE_KEY'),
        'container' => env('AZURE_STORAGE_CONTAINER'),
    ],
    name: 'Azure Files',
);
```

The component form builds an HTTPS connection string with `core.windows.net` as the endpoint suffix. Use a complete connection string when a sovereign cloud, emulator, SAS credential, or custom endpoint requires different connection details.

The container must already exist and the credential must permit the operations the application performs.

### Azure paths

Azure Blob Storage has a flat object namespace. The Flysystem adapter presents prefixes as folders, so folder IDs returned by this package are paths such as `projects/contracts`. Preserve the returned ID and pass it back to browse, upload, copy, and move operations.

## Common operations

### List and select drives

```php
$connected = $drives->listConnected($workspace);
$default = $drives->getDefault($workspace);

$drives->setDefault($workspace, $drive, actor: $user);
$drives->disconnect($workspace, $drive, actor: $user);
```

The first connected drive becomes the workspace default. Passing `null` where an operation accepts an optional drive resolves that default.

### Browse

```php
$root = $drives->browseFolder($workspace);

$folder = $drives->browseFolder(
    workspace: $workspace,
    drive: $drive,
    folderId: 'remote-folder-id-or-path',
);
```

The result contains `drive`, `folder`, `items`, and `count`. Each item includes:

```php
[
    'id' => 'provider-id-or-path',
    'name' => 'report.pdf',
    'is_folder' => false,
    'size' => 2048,
    'mime_type' => 'application/pdf',
    'web_view_link' => null,
    'thumbnail_url' => null,
    'modified_at' => '2026-09-03T12:00:00+00:00',
    'parent_id' => 'parent-id-or-path',
]
```

Provider IDs are opaque. Do not parse them or assume that OneDrive IDs and Azure paths have the same format.

### Copy and move

```php
$copy = $drives->copyFile(
    workspace: $workspace,
    from: $sourceDrive,
    fileId: $remoteFileId,
    to: $targetDrive,
    targetFolderId: 'root',
    newName: 'copy.pdf',
    actor: $user,
);

$move = $drives->moveFile(
    workspace: $workspace,
    from: $sourceDrive,
    fileId: $remoteFileId,
    to: $targetDrive,
    targetFolderId: 'destination-id',
    actor: $user,
);
```

Cross-provider moves copy the content first and delete the source only after the copy succeeds. Folder transfers return a `TransferResult`; inspect `errors` before treating a recursive copy as complete.

### Import and export

```php
$media = $drives->importFile(
    workspace: $workspace,
    drive: $drive,
    remoteFileId: $remoteFileId,
    targetFolder: $folder,
    actor: $user,
);

$remote = $drives->exportFile(
    workspace: $workspace,
    media: $media,
    drive: $drive,
    remoteFolderId: 'root',
);
```

Importing a remote folder as one media record is not supported. Browse it and import its files individually, or use the recursive cross-drive operations when both endpoints are connected drives.

## Security guidance

- Generate and verify OAuth state for every authorization attempt.
- Use HTTPS callback URLs outside local development.
- Request only the provider permissions the application needs.
- Never log connection strings, account keys, access tokens, refresh tokens, or the encrypted `credentials` attribute.
- Restrict Azure credentials to the required account/container operations.
- Keep Laravel's `APP_KEY` stable and protect database backups because connected-drive secrets are stored there in encrypted form.
- Apply authorization before connecting, browsing, copying, moving, importing, exporting, changing defaults, or disconnecting drives.

## Troubleshooting

### Azure client class not found

If an application reports that `AzureOss\Storage\Blob\BlobServiceClient` or `AzureBlobStorageAdapter` cannot be found, install the current adapter and regenerate Composer's autoloader:

```bash
composer require azure-oss/storage-blob-flysystem:^2.2
composer dump-autoload
```

Do not install `league/flysystem-azure-blob-storage` for this version of Tetranyble Storage; that is the legacy integration.

### OneDrive Graph class or `setAccessToken()` error

Current releases do not instantiate `Microsoft\Graph\Graph` and do not call `setAccessToken()`. Remove stale published or copied adapter code and update Tetranyble Storage. The `microsoft/microsoft-graph` package is not required for OneDrive support.

### OAuth callback fails

Check that:

- the configured callback URI exactly matches the provider registration;
- the callback contains both `code` and the expected `state`;
- the state was created before redirect and removed after callback;
- the client secret is current;
- `ONEDRIVE_TENANT_ID` permits the account being used.

### Azure authentication fails

Check that the container exists, the account name and key belong together, and the complete connection string has not been truncated by environment-file quoting. Prefer storing the whole connection string in one environment variable.

## Upgrading from the legacy adapters

Older installations used `microsoft/microsoft-graph` for OneDrive and `league/flysystem-azure-blob-storage` for Azure. The current adapters use Laravel HTTP and `azure-oss/storage-blob-flysystem`, respectively.

After updating Tetranyble Storage, update explicitly installed provider packages in the host application. Remove the legacy packages only when no other application code uses them:

```bash
composer remove microsoft/microsoft-graph league/flysystem-azure-blob-storage
composer require azure-oss/storage-blob-flysystem:^2.2
```

The stored OneDrive tokens and Azure credential keys retain the same shape, so existing `connected_drives` records do not require a data migration. Run the application test suite and verify one browse, upload, and download operation against non-production provider accounts before deployment.
