<?php

namespace Tetranyble\Storage\Http\Responses;

final class SafeDownloadFilename
{
    public static function from(string $filename, string $fallback = 'download'): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename !== '' ? $filename : $fallback;
    }
}
