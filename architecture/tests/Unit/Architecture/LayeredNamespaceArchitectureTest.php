<?php

namespace Tetranyble\Storage\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LayeredNamespaceArchitectureTest extends TestCase
{
    public function test_capability_domain_layers_do_not_depend_on_application_infrastructure_or_http(): void
    {
        foreach ($this->moduleLayerFiles('Domain') as $path) {
            $source = (string) file_get_contents($path);
            foreach (['\\Application\\', '\\Infrastructure\\'] as $forbidden) {
                $this->assertDoesNotMatchRegularExpression(
                    '/Tetranyble\\\\Storage\\\\Modules\\\\[^;\\s]+'.preg_quote($forbidden, '/').'/',
                    $source,
                    $this->relative($path).' makes Domain depend outward.',
                );
            }
            $this->assertStringNotContainsString('Tetranyble\\Storage\\Http\\', $source);
        }
    }

    public function test_capability_application_and_infrastructure_layers_do_not_depend_on_http(): void
    {
        foreach (['Application', 'Infrastructure'] as $layer) {
            foreach ($this->moduleLayerFiles($layer) as $path) {
                $this->assertStringNotContainsString(
                    'Tetranyble\\Storage\\Http\\',
                    (string) file_get_contents($path),
                    $this->relative($path).' depends on the HTTP adapter.',
                );
            }
        }
    }

    public function test_all_source_namespaces_match_psr4_paths(): void
    {
        foreach ($this->phpFiles('src') as $path) {
            $source = (string) file_get_contents($path);
            preg_match('/^namespace\s+([^;]+);/m', $source, $match);
            $this->assertNotEmpty($match, $this->relative($path).' has no namespace declaration.');

            $relative = substr($this->relative($path), strlen('src/'));
            $directory = dirname($relative);
            $expected = 'Tetranyble\\Storage'.($directory === '.' ? '' : '\\'.str_replace('/', '\\', $directory));
            $this->assertSame($expected, $match[1], $this->relative($path).' does not match its PSR-4 directory.');
        }
    }

    public function test_every_capability_directory_is_catalogued_and_uses_canonical_layers(): void
    {
        $catalog = json_decode(
            (string) file_get_contents($this->root().'/architecture/modules.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $known = array_keys($catalog['modules'] ?? []);
        sort($known);

        $directories = array_map('basename', glob($this->root().'/src/Modules/*', GLOB_ONLYDIR) ?: []);
        sort($directories);
        $this->assertSame($known, $directories);

        foreach ($directories as $module) {
            foreach (glob($this->root().'/src/Modules/'.$module.'/*', GLOB_ONLYDIR) ?: [] as $layer) {
                $this->assertContains(basename($layer), ['Domain', 'Application', 'Infrastructure']);
            }
        }
    }

    public function test_package_owned_eloquent_models_are_owned_by_capabilities(): void
    {
        $models = [
            'Media' => 'Media',
            'MediaDerivative' => 'Processing',
            'Folder' => 'Folder',
            'MediaShare' => 'Sharing',
            'UploadSession' => 'Upload',
            'DirectUploadSession' => 'DirectUpload',
            'ConnectedDrive' => 'CloudDrive',
            'Workspace' => 'Workspace',
            'User' => 'Workspace',
        ];

        foreach ($models as $model => $module) {
            $this->assertFileExists(
                $this->root().'/src/Modules/'.$module.'/Infrastructure/Persistence/Eloquent/Models/'.$model.'.php',
            );
        }
    }

    public function test_first_release_contains_no_namespace_alias_shims(): void
    {
        foreach ($this->phpFiles('src') as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('class_alias(', $source, $this->relative($path).' contains a compatibility alias.');
            $this->assertStringNotContainsString('Compatibility alias retained', $source);
        }
    }

    /** @return list<string> */
    private function moduleLayerFiles(string $layer): array
    {
        $files = [];
        foreach (glob($this->root().'/src/Modules/*/'.$layer, GLOB_ONLYDIR) ?: [] as $directory) {
            $files = array_merge($files, $this->phpFiles(ltrim(str_replace($this->root(), '', $directory), '/')));
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDirectory): array
    {
        $directory = $this->root().'/'.$relativeDirectory;
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root(), '', $path), '/');
    }
}
