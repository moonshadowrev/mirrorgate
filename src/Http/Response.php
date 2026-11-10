<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Http;

use MirrorGate\Support\Json;

final readonly class Response
{
    public function __construct(
        public int $status,
        public HeaderBag $headers,
        public string $body = '',          // empty for HEAD/204/304
    ) {
    }

    /**
     * @param list<array{0:string,1:string}> $extraHeaders same shape as HeaderBag::fromPairs
     */
    public static function text(int $status, string $body, array $extraHeaders = []): self
    {
        $pairs = [
            ['Content-Type', 'text/plain; charset=utf-8'],
            ['X-Content-Type-Options', 'nosniff'],
        ];
        foreach ($extraHeaders as $pair) {
            $pairs[] = $pair;
        }
        return new self($status, HeaderBag::fromPairs($pairs), $body);
    }

    /**
     * @param array<mixed> $data
     * @param list<array{0:string,1:string}> $extraHeaders same shape as HeaderBag::fromPairs
     */
    public static function json(int $status, array $data, array $extraHeaders = []): self
    {
        $pairs = [
            ['Content-Type', 'application/json'],
            ['Cache-Control', 'no-store'],
            ['X-Content-Type-Options', 'nosniff'],
        ];
        foreach ($extraHeaders as $pair) {
            $pairs[] = $pair;
        }
        return new self($status, HeaderBag::fromPairs($pairs), Json::encode($data));
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->headers->set($name, $value), $this->body);
    }

    public function withAddedHeader(string $name, string $value): self
    {
        return new self($this->status, $this->headers->add($name, $value), $this->body);
    }
}
