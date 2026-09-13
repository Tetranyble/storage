<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\DTO;

readonly class MediaContentInspection
{
    public function __construct(public ?string $detectedMimeType) {}

    public function isCompatibleWith(?string $declaredMimeType): bool
    {
        $declared = $this->normalize($declaredMimeType);
        $detected = $this->normalize($this->detectedMimeType);

        if ($declared === null
            || $declared === 'application/octet-stream'
            || $detected === null
            || in_array($detected, ['application/octet-stream', 'application/x-empty'], true)) {
            // Generic/empty detections are not strong enough evidence to reject
            // an otherwise permitted declared type.
            return true;
        }

        if ($declared === $detected) {
            return true;
        }

        $aliases = [
            'image/jpg' => ['image/jpeg'],
            'text/csv' => ['text/plain', 'application/csv'],
            'application/json' => ['text/plain'],
            'application/xml' => ['text/xml'],
            'text/xml' => ['application/xml'],
        ];
        if (in_array($detected, $aliases[$declared] ?? [], true)) {
            return true;
        }

        $zipContainers = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ];

        return in_array($declared, $zipContainers, true)
            && in_array($detected, ['application/zip', 'application/x-zip', 'application/x-zip-compressed'], true);
    }

    private function normalize(?string $mime): ?string
    {
        if ($mime === null) {
            return null;
        }

        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return $mime !== '' ? $mime : null;
    }
}
