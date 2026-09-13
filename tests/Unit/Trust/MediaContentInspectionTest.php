<?php

namespace Tetranyble\Storage\Tests\Unit\Trust;

use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaContentInspection;

class MediaContentInspectionTest extends TestCase
{
    public function test_exact_mime_match_is_compatible(): void
    {
        $inspection = new MediaContentInspection('image/jpeg');

        $this->assertTrue($inspection->isCompatibleWith('image/jpeg; charset=binary'));
    }

    public function test_high_confidence_mismatch_is_rejected(): void
    {
        $inspection = new MediaContentInspection('application/pdf');

        $this->assertFalse($inspection->isCompatibleWith('image/jpeg'));
    }

    public function test_office_open_xml_zip_container_is_accepted(): void
    {
        $inspection = new MediaContentInspection('application/zip');

        $this->assertTrue($inspection->isCompatibleWith(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));
    }

    public function test_generic_detected_binary_type_is_inconclusive_not_a_mismatch(): void
    {
        $inspection = new MediaContentInspection('application/octet-stream');

        $this->assertTrue($inspection->isCompatibleWith('video/mp4'));
    }

    public function test_empty_detection_is_inconclusive_not_a_mismatch(): void
    {
        $inspection = new MediaContentInspection('application/x-empty');

        $this->assertTrue($inspection->isCompatibleWith('text/plain'));
    }

    public function test_generic_declared_binary_type_does_not_create_false_positive(): void
    {
        $inspection = new MediaContentInspection('application/pdf');

        $this->assertTrue($inspection->isCompatibleWith('application/octet-stream'));
    }
}
