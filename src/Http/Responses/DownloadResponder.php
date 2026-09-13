<?php

namespace Tetranyble\Storage\Http\Responses;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\DownloadPayload;

class DownloadResponder
{
    public function response(DownloadPayload $payload): Response
    {
        return response($payload->binary, 200, [
            'Content-Type' => $payload->mime,
            'Content-Disposition' => (new ResponseHeaderBag())->makeDisposition('attachment', SafeDownloadFilename::from($payload->filename), 'download'),
            'Content-Length' => strlen($payload->binary),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
