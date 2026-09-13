# Storage Usage Inventory

This inventory was built from a repo-wide search before the package re-implementation.

## Core implementation found in app

- `app/Domain/FileSystem/*`
- `app/Domain/Media/*`
- `bootstrap/providers.php`

## Direct app usages found

- Controllers:
  `app/Http/Controllers/Workspace/ChunkedMediaUploadController.php`
  `app/Http/Controllers/Workspace/MediaController.php`
  `app/Http/Controllers/Workspace/MediaLibraryController.php`
  `app/Http/Controllers/Workspace/MediaShareController.php`
  `app/Http/Controllers/Workspace/ManageLoanController.php`
  `app/Http/Controllers/Workspace/WorkspaceAssetController.php`

- Commands:
  `app/Console/Commands/PurgeTemporaryMedia.php`

- Domain/services:
  `app/Domain/Documents/LoanOwnedDocumentService.php`
  `app/Services/DocumentRequests/DocumentRequestService.php`
  `app/Services/Workspace/WorkspaceOnboardingService.php`
  `app/Domain/Branding/Service/WorkSpaceBrandingService.php`
  `app/Domain/Loans/Services/LoanImportService.php`
  `app/Domain/Payslips/Services/PayslipImportService.php`
  `app/Domain/Customers/Services/CustomerImportService.php`

- Model/trait usages:
  `app/Models/Media.php`
  `app/Models/Workspace.php`
  `app/Models/User.php`
  `app/Models/Loan.php`
  `app/Models/Customer.php`
  `app/Models/Guarantor.php`
  `app/Models/NextOfKen.php`
  `app/Models/IdentityDocument.php`
  `app/Models/DocumentRequestItem.php`
  `app/Models/MediaMapping.php`
  `app/Traits/Uploader.php`

## Existing related tests found in app

- `tests/Unit/FileSystem/FileSystemTest.php`
- `tests/Unit/FileSystem/MediaServiceTest.php`
- `tests/Unit/MediableTraitTest.php`
- `tests/Unit/Model/MediaImageMetadataTest.php`
- `tests/Feature/Workspace/Media/WorkspaceMediaLibraryTest.php`
- `tests/Feature/Workspace/Media/ChunkedMediaUploadControllerTest.php`
- `tests/Feature/Workspace/Controller/DocumentRequestControllerTest.php`
- `tests/Feature/Workspace/LoanImportControllerTest.php`
- `tests/Unit/Services/PayslipStreamImporterTest.php`
- `tests/Unit/Jobs/PaySlipImportJobTest.php`

## Package-local test coverage added

- `tests/Unit/FileSystemTest.php`
- `tests/Unit/MediaServiceTest.php`
- `tests/Unit/MediableTraitTest.php`
- `tests/Unit/MediaImageMetadataTest.php`
- `tests/Feature/WorkspaceFileManagerServiceTest.php`
- `tests/Feature/PurgeTemporaryMediaCommandTest.php`
- `tests/Feature/MediaControllerTest.php`

## Package-owned HTTP layer

- `ChunkedMediaUploadController`
- `MediaController`
- `MediaLibraryController`
- `MediaShareController`
- Configurable authenticated/public route groups and per-controller overrides
- Workspace-scoped resource resolution through the `Workspace` contract

## Intentionally host-owned examples

- Credense import and workflow adapters:
  `LoanImportService`, `PayslipImportService`, `CustomerImportService`
  `DocumentRequestService`, `LoanOwnedDocumentService`

- Credense multi-workspace branding:
  `WorkspaceAssetController`, `WorkSpaceBrandingService`

- See `docs/host-application-integration.md` for package consumption examples.

## Replaced app concern

- `app/Traits/Uploader.php` is superseded by
  `Tetranyble\\Storage\\Concerns\\InteractsWithMedia`.

## Test harness

- Package tests run independently with Orchestra Testbench.
