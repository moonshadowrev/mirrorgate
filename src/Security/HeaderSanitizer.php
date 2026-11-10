<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Security;

use MirrorGate\Http\HeaderBag;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Support\Str;

final class HeaderSanitizer
{
    public const HOP_BY_HOP = ['connection', 'keep-alive', 'proxy-connection', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade'];

    /**
     * Headers that a `Connection: <name>` token is never allowed to strip. Honouring such a token
     * would let a peer delete message framing or identity headers (a known smuggling and cache
     * confusion technique).
     */
    public const PROTECTED = ['content-length', 'content-type', 'content-encoding', 'host', 'set-cookie', 'location'];

    /**
     * Removes the fixed hop-by-hop set and every field named in a Connection header
     * (except the protected framing and identity headers above).
     */
    public static function removeHopByHop(HeaderBag $h): HeaderBag
    {
        $names = self::HOP_BY_HOP;
        foreach ($h->getAll('connection') as $value) {
            foreach (explode(',', $value) as $token) {
                $token = strtolower(trim($token));
                if ($token === '' || !Str::isHeaderToken($token) || in_array($token, self::PROTECTED, true)) {
                    continue;
                }
                $names[] = $token;
            }
        }
        return $h->withoutNames(array_values(array_unique($names)));
    }

    /**
     * Drops, and logs by NAME only, every pair whose name is not an RFC 9110 token or whose
     * name or value contains CR, LF, NUL or another control character.
     *
     * @param list<array{0:string,1:string}> $pairs
     */
    public static function dropInvalid(array $pairs, LoggerInterface $log): HeaderBag
    {
        $valid = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_string($pair[0]) || !is_string($pair[1])) {
                $log->warning('Dropped malformed header pair', ['header' => '[malformed]']);
                continue;
            }
            [$name, $value] = $pair;
            if ($name === '' || !Str::isHeaderToken($name) || Str::hasControlChars($name) || Str::hasControlChars($value)) {
                $log->warning('Dropped invalid header', ['header' => Str::truncate(Str::stripControl($name), 64)]);
                continue;
            }
            $valid[] = [$name, $value];
        }
        try {
            return HeaderBag::fromPairs($valid);
        } catch (\InvalidArgumentException) {
            // HeaderBag is stricter than the checks above: fall back to adding pair by pair.
            $bag = new HeaderBag();
            foreach ($valid as [$name, $value]) {
                try {
                    $bag = $bag->add($name, $value);
                } catch (\InvalidArgumentException) {
                    $log->warning('Dropped invalid header', ['header' => Str::truncate(Str::stripControl($name), 64)]);
                }
            }
            return $bag;
        }
    }
}
