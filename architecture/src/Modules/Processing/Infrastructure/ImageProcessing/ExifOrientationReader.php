<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing;

final class ExifOrientationReader
{
    public function orientation(string $binary, ?string $mimeType = null): int
    {
        if ($mimeType !== null && strtolower($mimeType) !== 'image/jpeg') {
            return 1;
        }

        $length = strlen($binary);
        if ($length < 4 || substr($binary, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;
        while ($offset + 4 <= $length) {
            if (ord($binary[$offset]) !== 0xFF) {
                break;
            }

            $marker = ord($binary[$offset + 1]);
            $offset += 2;

            if ($marker === 0xD9 || $marker === 0xDA) {
                break;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('n', substr($binary, $offset, 2))[1] ?? 0;
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                break;
            }

            if ($marker === 0xE1) {
                $payload = substr($binary, $offset + 2, $segmentLength - 2);
                $orientation = $this->fromExifPayload($payload);
                if ($orientation !== 1) {
                    return $orientation;
                }
            }

            $offset += $segmentLength;
        }

        return 1;
    }

    private function fromExifPayload(string $payload): int
    {
        if (strlen($payload) < 14 || substr($payload, 0, 6) !== "Exif\0\0") {
            return 1;
        }

        $tiff = substr($payload, 6);
        $byteOrder = substr($tiff, 0, 2);
        $littleEndian = $byteOrder === 'II';
        if (! $littleEndian && $byteOrder !== 'MM') {
            return 1;
        }

        if ($this->readU16($tiff, 2, $littleEndian) !== 42) {
            return 1;
        }

        $ifdOffset = $this->readU32($tiff, 4, $littleEndian);
        if ($ifdOffset < 0 || $ifdOffset + 2 > strlen($tiff)) {
            return 1;
        }

        $count = $this->readU16($tiff, $ifdOffset, $littleEndian);
        $entryOffset = $ifdOffset + 2;

        for ($index = 0; $index < $count; $index++) {
            $current = $entryOffset + ($index * 12);
            if ($current + 12 > strlen($tiff)) {
                break;
            }

            $tag = $this->readU16($tiff, $current, $littleEndian);
            if ($tag !== 0x0112) {
                continue;
            }

            $type = $this->readU16($tiff, $current + 2, $littleEndian);
            $values = $this->readU32($tiff, $current + 4, $littleEndian);
            if ($type !== 3 || $values < 1) {
                return 1;
            }

            $orientation = $this->readU16($tiff, $current + 8, $littleEndian);

            return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        }

        return 1;
    }

    private function readU16(string $data, int $offset, bool $littleEndian): int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            return -1;
        }

        $format = $littleEndian ? 'v' : 'n';

        return (int) (unpack($format, substr($data, $offset, 2))[1] ?? -1);
    }

    private function readU32(string $data, int $offset, bool $littleEndian): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            return -1;
        }

        $format = $littleEndian ? 'V' : 'N';

        return (int) (unpack($format, substr($data, $offset, 4))[1] ?? -1);
    }
}
