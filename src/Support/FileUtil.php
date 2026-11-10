<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Support;

final class FileUtil
{
    private static int $dirMode = 0750;
    private static int $fileMode = 0640;

    public static function configureModes(int $dirMode, int $fileMode): void
    {
        self::$dirMode = $dirMode & 0777;
        self::$fileMode = $fileMode & 0777;
    }

    public static function dirMode(): int
    {
        return self::$dirMode;
    }

    public static function fileMode(): int
    {
        return self::$fileMode;
    }

    /**
     * Creates a directory (recursively). Every directory created by this call receives a deny-all
     * .htaccess and an empty index.html, whether or not the directory is believed to be web-visible.
     *
     * @throws \RuntimeException
     */
    public static function ensureDir(string $dir, ?int $mode = null): void
    {
        if ($dir === '') {
            throw new \RuntimeException('Empty directory path.');
        }
        if (is_dir($dir)) {
            return;
        }
        $mode = ($mode ?? self::$dirMode) & 0777;

        $missing = [];
        $probe = rtrim($dir, '/\\');
        while ($probe !== '' && !is_dir($probe)) {
            $missing[] = $probe;
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        foreach (array_reverse($missing) as $path) {
            if (!is_dir($path) && !@mkdir($path, $mode, false) && !is_dir($path)) {
                throw new \RuntimeException('Unable to create directory.');
            }
            @chmod($path, $mode);
            self::writeDenyFiles($path);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory.');
        }
    }

    private static function writeDenyFiles(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $body = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
            if (@file_put_contents($htaccess, $body, LOCK_EX) !== false) {
                @chmod($htaccess, self::$fileMode);
            }
        }
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            if (@file_put_contents($index, '') !== false) {
                @chmod($index, self::$fileMode);
            }
        }
    }

    /**
     * Writes a file atomically: temp file in the same directory, flush, rename.
     *
     * @throws \RuntimeException
     */
    public static function atomicWrite(string $path, string $data, ?int $mode = null): void
    {
        $dir = dirname($path);
        $mode = ($mode ?? self::$fileMode) & 0777;
        $tmp = $dir . '/' . bin2hex(random_bytes(8)) . '.part';
        // Created owner-only: the final mode is applied after the data is written, so the content must
        // never be readable by anyone else in between.
        $previousUmask = umask(0177);
        try {
            $h = @fopen($tmp, 'xb');
        } finally {
            umask($previousUmask);
        }
        if ($h === false) {
            throw new \RuntimeException('Unable to create temporary file.');
        }
        $ok = true;
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($h, substr($data, $written));
            if ($n === false || $n === 0) {
                $ok = false;
                break;
            }
            $written += $n;
        }
        if ($ok) {
            $ok = @fflush($h);
        }
        fclose($h);
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write temporary file.');
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to move file into place.');
        }
    }

    /**
     * Reads a whole file. Returns null when it is missing, unreadable or larger than $maxBytes.
     */
    public static function readFile(string $path, int $maxBytes): ?string
    {
        if ($maxBytes < 0 || !is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > $maxBytes) {
            return null;
        }
        $data = @file_get_contents($path, false, null, 0, $maxBytes + 1);
        if ($data === false || strlen($data) > $maxBytes) {
            return null;
        }
        return $data;
    }

    public static function safeUnlink(string $path): bool
    {
        if (!is_file($path) && !is_link($path)) {
            return false;
        }
        return @unlink($path);
    }

    /**
     * Symlink-aware containment check. Both paths must exist.
     */
    public static function isInside(string $path, string $root): bool
    {
        $rp = realpath($path);
        $rr = realpath($root);
        if ($rp === false || $rr === false) {
            return false;
        }
        $rr = rtrim($rr, '/\\');
        if ($rr === '') {
            return true;
        }
        return $rp === $rr || str_starts_with($rp, $rr . DIRECTORY_SEPARATOR);
    }

    /**
     * @return resource|null
     */
    public static function openLock(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            try {
                self::ensureDir($dir);
            } catch (\RuntimeException) {
                return null;
            }
        }
        $existed = file_exists($path);
        $h = @fopen($path, 'c+b');
        if ($h === false) {
            return null;
        }
        if (!$existed) {
            @chmod($path, 0640);
        }
        return $h;
    }
}
