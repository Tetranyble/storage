<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$read = static fn (string $path): string => is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : '';
$requiredQueries = ['BrowseWorkspace', 'TrashWorkspace', 'StarredWorkspace', 'SharedWithMe', 'MediaVersions', 'SearchWorkspace', 'RecentWorkspace', 'ActivityWorkspace'];
$requiredHandlers = array_map(static fn (string $name): string => $name.'Handler', $requiredQueries);

$port = $read('src/Modules/Workspace/Application/Contracts/WorkspaceReadModel.php');
if ($port === '' || str_contains($port, 'Illuminate\\') || str_contains($port, 'Infrastructure\\')) {
    $failures[] = 'WorkspaceReadModel must exist as a framework-neutral Application port.';
}
foreach ($requiredQueries as $query) {
    $path = 'src/Modules/Workspace/Application/Queries/'.$query.'.php';
    $source = $read($path);
    if ($source === '' || str_contains($source, 'Illuminate\\') || str_contains($source, 'Infrastructure\\')) {
        $failures[] = $query.' must be a framework-neutral read query DTO.';
    }
}
foreach ($requiredHandlers as $handler) {
    $path = 'src/Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers/'.$handler.'.php';
    if ($read($path) === '') {
        $failures[] = 'Missing Eloquent read handler '.$handler.'.';
    }
}
$controller = $read('src/Http/Controllers/MediaLibraryController.php');
if (! str_contains($controller, 'WorkspaceReadModel') || str_contains($controller, 'WorkspaceFileQueryService')) {
    $failures[] = 'MediaLibraryController must depend on WorkspaceReadModel, not the Eloquent compatibility adapter.';
}
$compat = $read('src/Modules/Workspace/Infrastructure/Queries/WorkspaceFileQueryService.php');
$lineCount = $compat === '' ? 0 : substr_count($compat, "\n") + 1;
if ($lineCount > 150) {
    $failures[] = 'WorkspaceFileQueryService must remain a thin compatibility adapter (<=150 lines).';
}
foreach (['ResourceVisibilityQuery', 'cursorPaginate(', 'Folder::query()', 'Media::query()'] as $queryDetail) {
    if (str_contains($compat, $queryDetail)) {
        $failures[] = 'WorkspaceFileQueryService reintroduced persistence/query composition: '.$queryDetail;
    }
}
if ($failures !== []) {
    fwrite(STDERR, "CQRS read-model architecture failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}
echo 'CQRS read-model architecture passed: 8 framework-neutral query DTOs, 8 Eloquent handlers, thin compatibility adapter, HTTP depends on read port.'.PHP_EOL;
