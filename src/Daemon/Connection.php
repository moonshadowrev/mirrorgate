<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * One non-blocking socket with a bounded-by-the-caller write buffer.
 */
final class Connection
{
    public const PLAIN_CHUNK = 65536;
    public const TLS_CHUNK = 16384;
    private const MAX_ZERO_WRITES = 3;

    /** @var resource */
    private $stream;
    private string $out = '';
    private int $zeroWrites = 0;
    private bool $tls;

    public bool $eof = false;
    public bool $readClosed = false;
    public bool $writeShutdown = false;
    public bool $paused = false;
    public bool $writeWatched = false;

    /** @param resource $stream */
    public function __construct($stream, bool $tls = false)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('stream expected');
        }
        $this->stream = $stream;
        $this->tls = $tls;
        @stream_set_blocking($stream, false);
        @stream_set_write_buffer($stream, 0);
    }

    /** @return resource */
    public function stream()
    {
        return $this->stream;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function markTls(): void
    {
        $this->tls = true;
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * Reads up to $max bytes. Returns '' when nothing is available; sets $eof when the peer closed or the socket failed.
     */
    public function read(int $max): string
    {
        if (!is_resource($this->stream)) {
            $this->eof = true;
            return '';
        }
        $data = @fread($this->stream, max(1, $max));
        if ($data === false) {
            $this->eof = true;
            return '';
        }
        if ($data === '') {
            if (@feof($this->stream)) {
                $this->eof = true;
            }
            return '';
        }
        return $data;
    }

    /** True when the stream layer already holds decrypted bytes that select() cannot see. */
    public function hasPending(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        return stream_get_meta_data($this->stream)['unread_bytes'] > 0;
    }

    public function queue(string $data): void
    {
        $this->out .= $data;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->out);
    }

    /**
     * Writes as much of the buffer as the socket accepts.
     *
     * @param bool $writableEvent true when select() reported the socket writable; only then does a zero-byte
     *                            write count towards the stall limit
     * @return int|false bytes written, or false when the connection must be dropped
     */
    public function flush(bool $writableEvent, int $maxChunks = 16): int|false
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $total = 0;
        $chunk = $this->tls ? self::TLS_CHUNK : self::PLAIN_CHUNK;
        for ($i = 0; $i < $maxChunks && $this->out !== ''; $i++) {
            $len = strlen($this->out);
            $piece = $len > $chunk ? substr($this->out, 0, $chunk) : $this->out;
            $written = @fwrite($this->stream, $piece);
            if ($written === false) {
                return false;
            }
            if ($written === 0) {
                if ($writableEvent && ++$this->zeroWrites >= self::MAX_ZERO_WRITES) {
                    return false;
                }
                break;
            }
            $this->zeroWrites = 0;
            $total += $written;
            $this->out = $written >= $len ? '' : substr($this->out, $written);
            if ($written < strlen($piece)) {
                break;
            }
        }
        return $total;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->out = '';
    }
}
