<?php

namespace Tetranyble\Storage\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class CircleCiConfigurationTest extends TestCase
{
    public function test_circleci_mirrors_release_critical_verification_without_publish_authority(): void
    {
        $root = dirname(__DIR__, 3);
        $config = (string) file_get_contents($root.'/.circleci/config.yml');

        foreach ([
            'composer run production:gate',
            'composer audit --locked',
            'postgres:17',
            'mysql:8.4',
            'quay.io/minio/minio:latest',
            'composer benchmark:queries',
            'name: CircleCI Required CI',
        ] as $required) {
            $this->assertStringContainsString($required, $config);
        }

        foreach ([
            'git push ',
            'git tag -a',
            'gh release',
            'verify-packagist-release.php',
            'packagist.org/api/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $config);
        }
    }
}
