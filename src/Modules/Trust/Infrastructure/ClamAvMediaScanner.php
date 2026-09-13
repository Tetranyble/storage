<?php

namespace Tetranyble\Storage\Modules\Trust\Infrastructure;

use RuntimeException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanResult;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

class ClamAvMediaScanner implements MediaScanner
{
    public function __construct(private readonly FileSystemContract $files) {}

    public function scan(MediaScanTarget $target): MediaScanResult
    {
        $binary = trim((string) config('tetranyble-storage.trust.virus_scanning.clamav_binary', 'clamscan'));
        if ($binary === '') {
            return MediaScanResult::failed('clamav', 'No ClamAV binary is configured.');
        }

        $maxBytes = max(1, (int) config(
            'tetranyble-storage.trust.virus_scanning.max_scan_bytes',
            config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024),
        ));
        if ($target->size !== null && $target->size->bytes > $maxBytes) {
            return MediaScanResult::failed('clamav', sprintf(
                'Media exceeds the configured scan ceiling (%d bytes).',
                $maxBytes,
            ));
        }

        if (! function_exists('proc_open')) {
            return MediaScanResult::failed('clamav', 'proc_open() is unavailable; ClamAV cannot be executed.');
        }

        $source = $this->files->readStream($target->path->value, $target->disk);
        if (! is_resource($source)) {
            return MediaScanResult::failed('clamav', 'Unable to open the stored media for scanning.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'tetranyble-scan-');
        if ($tempPath === false) {
            fclose($source);

            return MediaScanResult::failed('clamav', 'Unable to allocate a temporary scan file.');
        }

        try {
            $targetStream = fopen($tempPath, 'wb');
            if (! is_resource($targetStream)) {
                throw new RuntimeException('Unable to open temporary scan file.');
            }

            try {
                $copied = stream_copy_to_stream($source, $targetStream, $maxBytes + 1);
            } finally {
                fclose($targetStream);
            }

            if ($copied === false) {
                return MediaScanResult::failed('clamav', 'Unable to copy media into the scan sandbox.');
            }
            if ($copied > $maxBytes) {
                return MediaScanResult::failed('clamav', sprintf(
                    'Media exceeds the configured scan ceiling (%d bytes).',
                    $maxBytes,
                ));
            }

            return $this->runClamAv($binary, $tempPath);
        } catch (\Throwable $exception) {
            return MediaScanResult::failed('clamav', $exception->getMessage());
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            @unlink($tempPath);
        }
    }

    private function runClamAv(string $binary, string $tempPath): MediaScanResult
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open([$binary, '--no-summary', '--stdout', $tempPath], $descriptors, $pipes);
        if (! is_resource($process)) {
            return MediaScanResult::failed('clamav', 'Unable to start ClamAV.');
        }

        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], false);
        }

        $timeoutSeconds = max(1, (int) config('tetranyble-storage.trust.virus_scanning.timeout_seconds', 30));
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $timedOut = false;

        try {
            while (true) {
                $stdout .= $this->drain($pipes[1]);
                $stderr .= $this->drain($pipes[2]);

                $status = proc_get_status($process);
                if (! is_array($status)) {
                    break;
                }

                if (! ($status['running'] ?? false)) {
                    $exitCode = isset($status['exitcode']) ? (int) $status['exitcode'] : null;
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    @proc_terminate($process);
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if (is_array($status) && ($status['running'] ?? false)) {
                        @proc_terminate($process, 9);
                    }
                    break;
                }

                usleep(25_000);
            }

            $stdout .= $this->drain($pipes[1]);
            $stderr .= $this->drain($pipes[2]);
        } finally {
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }

            $closeCode = proc_close($process);
            if ($exitCode === null && is_int($closeCode) && $closeCode >= 0) {
                $exitCode = $closeCode;
            }
        }

        if ($timedOut) {
            return MediaScanResult::failed(
                'clamav',
                sprintf('ClamAV exceeded the configured scan timeout (%d seconds).', $timeoutSeconds),
            );
        }

        $output = trim($stdout."\n".$stderr);
        if ($exitCode === 0) {
            return MediaScanResult::clean('clamav');
        }

        if ($exitCode === 1) {
            $signature = null;
            if (preg_match('/:\s+(.+?)\s+FOUND(?:\r?\n|$)/', $output, $matches) === 1) {
                $signature = trim($matches[1]);
            }

            return MediaScanResult::infected('clamav', $signature, $output ?: 'Malware detected.');
        }

        return MediaScanResult::failed(
            'clamav',
            $output ?: 'ClamAV returned exit code '.($exitCode ?? 'unknown').'.',
        );
    }

    /** @param resource $stream */
    private function drain($stream): string
    {
        $chunk = stream_get_contents($stream);

        return is_string($chunk) ? $chunk : '';
    }
}
