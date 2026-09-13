<?php

namespace Tetranyble\Storage\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class ProductionGateTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composer(): array
    {
        $json = file_get_contents(dirname(__DIR__, 3).'/composer.json');
        $this->assertIsString($json);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $composer;
    }

    public function test_production_gate_runs_architecture_static_format_and_tests(): void
    {
        $scripts = $this->composer()['scripts'] ?? [];

        $this->assertSame(['@verify'], $scripts['production:gate'] ?? null);
        $this->assertSame(
            ['@architecture', '@analyse', '@format:check', '@test'],
            $scripts['verify'] ?? null,
        );
        $this->assertSame(
            ['@architecture:layers', '@architecture:domain', '@architecture:application', '@architecture:adapters', '@architecture:providers', '@architecture:read-model', '@architecture:resilience', '@architecture:http', '@architecture:reliability', '@architecture:release', '@architecture:lifecycle', '@architecture:modules', '@architecture:dependencies', '@architecture:complexity'],
            $scripts['architecture'] ?? null,
        );
    }

    public function test_architectural_debt_baselines_are_versioned_and_framework_debt_is_zero(): void
    {
        $root = dirname(__DIR__, 3);

        $dependency = json_decode(
            (string) file_get_contents($root.'/architecture/dependency-baseline.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $complexity = json_decode(
            (string) file_get_contents($root.'/architecture/complexity-baseline.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertNotEmpty($dependency['rules'] ?? []);
        $this->assertSame([], $dependency['violations'] ?? null);
        $this->assertFileExists($root.'/scripts/check-domain-purity.php');
        $this->assertFileExists($root.'/scripts/check-application-boundaries.php');
        $this->assertFileExists($root.'/scripts/check-adapter-isolation.php');
        $this->assertFileExists($root.'/scripts/check-provider-registry.php');
        $this->assertFileExists($root.'/scripts/check-read-model-cqrs.php');
        $this->assertFileExists($root.'/scripts/check-async-resilience.php');
        $this->assertFileExists($root.'/scripts/check-http-integration.php');
        $this->assertFileExists($root.'/scripts/check-production-reliability.php');
        $this->assertFileExists($root.'/scripts/check-release-readiness.php');
        $modules = json_decode(
            (string) file_get_contents($root.'/architecture/modules.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $moduleDependencies = json_decode(
            (string) file_get_contents($root.'/architecture/module-dependency-baseline.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertNotEmpty($modules['modules'] ?? []);
        $this->assertNotEmpty($moduleDependencies['allowed_edges'] ?? []);
        $this->assertSame(350, $complexity['maximum_new_class_lines'] ?? null);
        $this->assertNotEmpty($complexity['oversized_files'] ?? []);
    }

    public function test_ci_uses_the_production_gate_on_the_primary_quality_job(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

        $this->assertStringContainsString('composer run production:gate', $workflow);
        $this->assertStringContainsString('composer audit', $workflow);
        $this->assertStringContainsString('query-performance:', $workflow);
        $this->assertStringContainsString('composer benchmark:queries', $workflow);
    }
}
