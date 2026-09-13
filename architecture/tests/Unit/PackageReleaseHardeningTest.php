<?php

namespace Tetranyble\Storage\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackageReleaseHardeningTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composer(): array
    {
        $json = file_get_contents(__DIR__.'/../../composer.json');
        $this->assertIsString($json);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $composer;
    }

    public function test_supported_laravel_runtime_range_is_explicit(): void
    {
        $composer = $this->composer();
        $require = $composer['require'] ?? [];

        $this->assertSame('^8.2', $require['php'] ?? null);
        $this->assertSame('^12.0|^13.0', $require['illuminate/support'] ?? null);
        $this->assertSame('^12.0|^13.0', $require['illuminate/database'] ?? null);
        $this->assertSame('^12.0|^13.0', $require['illuminate/auth'] ?? null);
        $this->assertSame('^12.0|^13.0', $require['illuminate/cache'] ?? null);
    }

    public function test_cloud_provider_sdks_are_optional_runtime_dependencies(): void
    {
        $composer = $this->composer();
        $require = $composer['require'] ?? [];
        $suggest = $composer['suggest'] ?? [];

        foreach ([
            'google/apiclient',
            'spatie/dropbox-api',
            'league/flysystem-aws-s3-v3',
            'azure-oss/storage-blob-flysystem',
            'league/flysystem-google-cloud-storage',
            'cloudinary/cloudinary_php',
        ] as $package) {
            $this->assertArrayNotHasKey($package, $require);
            $this->assertArrayHasKey($package, $suggest);
        }

        $this->assertArrayNotHasKey('league/flysystem-azure-blob-storage', $require);
        $this->assertArrayNotHasKey('microsoft/microsoft-graph', $require);
        $this->assertArrayNotHasKey('microsoft/microsoft-graph', $composer['require-dev'] ?? []);
    }

    public function test_first_stable_schema_is_flattened_and_release_documents_exist(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root.'/CHANGELOG.md');
        $this->assertFileExists($root.'/docs/RELEASE_CHECKLIST.md');
        $this->assertFileExists($root.'/docs/PERFORMANCE_AND_INDEXES.md');
        $this->assertFileExists($root.'/docs/STEP_12_RELEASE_HARDENING.md');
        $this->assertFileExists($root.'/scripts/check-release-readiness.php');

        $migrations = glob($root.'/database/migrations/*.php') ?: [];
        foreach ($migrations as $migration) {
            $this->assertDoesNotMatchRegularExpression('/_(add|alter|update)_/', basename($migration));
        }
    }

    public function test_release_ci_has_real_database_performance_lane(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

        $this->assertStringContainsString('query-performance:', $workflow);
        $this->assertStringContainsString('composer benchmark:queries', $workflow);
        $this->assertStringContainsString('name: Required CI', $workflow);
        $this->assertStringContainsString('PostgreSQL', $workflow);
        $this->assertStringContainsString('MySQL', $workflow);
    }

    public function test_release_builds_and_validates_artifacts_before_pushing_the_public_tag(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');

        $archive = strpos($workflow, 'Build deterministic source archives from the exact tested commit');
        $verify = strpos($workflow, 'Verify packaged Composer metadata');
        $tag = strpos($workflow, 'Create and push the immutable annotated release tag');

        $this->assertIsInt($archive);
        $this->assertIsInt($verify);
        $this->assertIsInt($tag);
        $this->assertLessThan($verify, $archive);
        $this->assertLessThan($tag, $verify);
    }
}
