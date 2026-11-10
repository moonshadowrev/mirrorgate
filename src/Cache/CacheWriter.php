<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

/**
 * Atomic file writer: exclusive-create temp file in the target directory, fflush, chmod, rename.
 * Temp files end in ".part" so that the garbage collector can find orphans left by crashes.
 */
final class CacheWriter
{
    public const TEMP_PATTERN = '/^[0-9a-f]{64}\.[0-9a-f]{12}\.part$/D';

    public function __construct(private readonly int $fileMode)
    {
    }

    /**
     * @param string $path absolute target path; its directory must exist
     * @return bool false on any I/O problem (the temp file is removed)
     */
    public function write(string $path, string $contents): bool
    {
        try {
            $tmp = dirname($path) . '/' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.part';
        } catch (\Throwable) {
            return false;
        }
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            return false;
        }
        $ok = true;
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($handle, $written === 0 ? $contents : substr($contents, $written));
            if ($n === false || $n === 0) {
                $ok = false;
                break;
            }
            $written += $n;
        }
        if ($ok) {
            $ok = @fflush($handle);
        }
        @fclose($handle);
        if ($ok) {
            @chmod($tmp, $this->fileMode);
            $ok = @rename($tmp, $path);
        }
        if (!$ok) {
            @unlink($tmp);
        }

        return $ok;
    }
}
