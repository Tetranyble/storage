<?php

namespace Tetranyble\Storage\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponder
{
    /** @param array<string, mixed> $details
     *  @param array<string, string|string[]> $headers
     */
    public function error(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status, $headers);
    }
}
