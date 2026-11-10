<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Cache;

use MirrorGate\Config\CacheConfig;
use MirrorGate\Http\BadRequestException;
use MirrorGate\Http\HeaderBag;
use MirrorGate\Http\PathNormalizer;
use MirrorGate\Http\Request;

/**
 * Builds cache keys. Only validated request data (the normalized target and the selected Vary
 * header values) is hashed; no request data ever becomes part of a file path.
 *
 * primaryHash does not contain the rewrite fingerprint, so purges find entries that were written
 * under an older rewrite configuration. variantHash does.
 */
final class CacheKeyBuilder
{
    private const MAX_SELECTOR_NAMES = 8;
    private const MAX_VALUE_BYTES = 1024;

    /** @var list<string> lowercase names of query parameters excluded from the key */
    private array $ignoredParams;

    /**
     * @param \Closure(): int $generation returns the current cache generation
     */
    public function __construct(
        CacheConfig $config,
        private readonly string $instanceId,
        private readonly string $rewriteFingerprint,
        private readonly \Closure $generation,
    ) {
        $ignored = [];
        foreach ($config->ignoreQueryParams as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $ignored[] = $name;
            }
        }
        $this->ignoredParams = $ignored;
    }

    /**
     * @param list<string> $varySelector lowercase Vary header names
     */
    public function build(Request $request, array $varySelector): CacheKey
    {
        $pathAndQuery = $this->normalizeTarget($request->target->path, $request->target->query);
        $primary = $this->primaryHashFor($pathAndQuery);
        $selector = self::canonicalSelector($varySelector);

        return new CacheKey($primary, $this->variantHash($primary, $selector, $request->headers), $pathAndQuery);
    }

    /** Primary hash for an already normalized path and query. GET and HEAD share one class. */
    public function primaryHashFor(string $pathAndQuery): string
    {
        return hash('sha256', "GET\n" . $this->instanceId . "\n" . ($this->generation)() . "\n" . $pathAndQuery);
    }

    /**
     * @param list<string> $selector canonical selector (see canonicalSelector())
     */
    public function variantHash(string $primaryHash, array $selector, HeaderBag $requestHeaders): string
    {
        $parts = [$primaryHash, $this->rewriteFingerprint];
        foreach (self::canonicalSelector($selector) as $name) {
            $parts[] = $name . ':' . self::selectedValue($requestHeaders, $name);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Normalizes a path and a raw query exactly like build() does (applies CACHE_IGNORE_QUERY_PARAMS).
     */
    public function normalizeTarget(string $path, string $query): string
    {
        $query = $this->normalizeQuery($query);

        return $path . ($query !== '' ? '?' . $query : '');
    }

    /** The query as it takes part in the key: CACHE_IGNORE_QUERY_PARAMS removed. */
    public function normalizeQuery(string $query): string
    {
        if ($query !== '' && $this->ignoredParams !== []) {
            $kept = [];
            foreach (explode('&', $query) as $segment) {
                $eq = strpos($segment, '=');
                $name = strtolower(rawurldecode($eq === false ? $segment : substr($segment, 0, $eq)));
                if (!in_array($name, $this->ignoredParams, true)) {
                    $kept[] = $segment;
                }
            }
            $query = implode('&', $kept);
        }

        return $query;
    }

    /**
     * Normalizes an operator supplied "/path?query" string (purge input). Returns null for values
     * that cannot be a normalized target (no leading slash, control bytes, backslash, absurd length).
     */
    public function normalizePathAndQuery(string $input): ?string
    {
        if ($input === '' || $input[0] !== '/' || strlen($input) > 8192) {
            return null;
        }
        // The same canonicalization as for live requests, so a purge hashes exactly what a lookup hashed.
        try {
            $target = PathNormalizer::normalize($input);
        } catch (BadRequestException) {
            return null;
        }

        return $this->normalizeTarget($target->path, $target->query);
    }

    /**
     * Lowercases, validates, de-duplicates and sorts Vary header names.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function canonicalSelector(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = strtolower($name);
            if ($name !== '' && strlen($name) <= 64 && preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) === 1
                && $name !== '*' && $name !== 'accept-encoding'
            ) {
                $out[$name] = true;
            }
        }
        $names = array_keys($out);
        sort($names, SORT_STRING);

        return array_slice($names, 0, self::MAX_SELECTOR_NAMES);
    }

    private static function selectedValue(HeaderBag $headers, string $name): string
    {
        $value = implode(',', $headers->getAll($name));
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($name === 'origin' && $value !== '' && preg_match('#^[a-z][a-z0-9+.-]*://[^/\s]+$#iD', $value) !== 1) {
            return 'invalid';
        }

        return substr($value, 0, self::MAX_VALUE_BYTES);
    }
}
