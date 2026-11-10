<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

use MirrorGate\Config\Config;
use MirrorGate\Log\LoggerInterface;
use MirrorGate\Security\CidrList;

/**
 * WebSocket reverse proxy.
 *
 * The opening handshake is parsed and validated, rebuilt from scratch for the origin, and the origin's answer is
 * verified before the connection becomes an opaque byte pipe. Frames are never parsed.
 */
final class WebSocketProxy implements ForwarderInterface
{
    private const STATUS_TEXT = [
        400 => 'Bad Request',
        403 => 'Forbidden',
        421 => 'Misdirected Request',
        426 => 'Upgrade Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    private ?Acceptor $acceptor = null;
    private readonly CidrList $trusted;
    /** @var array<string,true> */
    private array $hostAllow = [];

    public function __construct(
        private readonly ListenerSpec $spec,
        private readonly Config $config,
        private readonly LoggerInterface $log,
        private readonly TargetResolver $resolver,
        private readonly ConnectionLimiter $limiter,
    ) {
        $this->trusted = new CidrList($config->security->trustedProxies);
        foreach ($config->mirror->allowedHosts as $host) {
            $this->hostAllow[strtolower(rtrim($host, '.'))] = true;
        }
        if ($config->mirror->wsPublicUrl !== null) {
            $host = parse_url($config->mirror->wsPublicUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $this->hostAllow[strtolower(rtrim($host, '.'))] = true;
            }
        }
    }

    public function name(): string
    {
        return $this->spec->id();
    }

    public function start(EventLoop $loop): void
    {
        $this->acceptor = new Acceptor($this->spec, $this->config->daemon, $this->trusted, $loop, $this->limiter, $this->log, true);
        $this->acceptor->open(function (Session $s): void {
            $this->begin($s);
        });
    }

    public function stop(): void
    {
        $this->acceptor?->closeAll();
        $this->acceptor = null;
    }

    public function closeListener(): void
    {
        $this->acceptor?->closeListener();
    }

    private function begin(Session $s): void
    {
        $maxHeader = $this->config->daemon->maxHeaderBytes;
        $buffer = '';
        $s->setPhase('handshake');
        $s->setClientHandler(function (Session $s) use (&$buffer, $maxHeader): void {
            $client = $s->client();
            for ($i = 0; $i < 4; $i++) {
                $data = $client->read(4096);
                if ($data === '') {
                    break;
                }
                $buffer .= $data;
                if (strlen($buffer) > $maxHeader + 4) {
                    $this->reject($s, 431, 'handshake_too_large');
                    return;
                }
            }
            if (preg_match('/(?<!\r)\n/', $buffer) === 1) {
                $this->reject($s, 400, 'bare_lf');
                return;
            }
            $end = strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                if ($client->eof) {
                    $s->close('client_eof_in_handshake');
                } elseif (strlen($buffer) > $maxHeader) {
                    $this->reject($s, 431, 'handshake_too_large');
                }
                return;
            }
            if ($end > $maxHeader) {
                $this->reject($s, 431, 'handshake_too_large');
                return;
            }
            if (strlen($buffer) > $end + 4) {
                // Nothing may follow the request head before the 101 answer.
                $this->reject($s, 400, 'data_after_handshake');
                return;
            }
            $s->setClientHandler(null);
            $this->handleRequest($s, substr($buffer, 0, $end));
        });
        $s->watchClient();
    }

    private function handleRequest(Session $s, string $block): void
    {
        $spec = $this->spec;
        try {
            $req = WsHandshake::parseRequest($block);
        } catch (\InvalidArgumentException $e) {
            $this->reject($s, $e->getCode() >= 400 ? $e->getCode() : 400, 'bad_request');
            return;
        }
        $h = $req['headers'];

        foreach (['host', 'upgrade', 'sec-websocket-key', 'sec-websocket-version', 'origin', 'sec-websocket-protocol', 'sec-websocket-extensions'] as $single) {
            if (count($h[$single] ?? []) > 1) {
                $this->reject($s, 400, 'duplicate_' . $single);
                return;
            }
        }
        if (isset($h['content-length']) || isset($h['transfer-encoding'])) {
            $this->reject($s, 400, 'body_on_handshake');
            return;
        }
        if (!isset($h['host'])) {
            $this->reject($s, 400, 'missing_host');
            return;
        }
        $hostName = self::hostWithoutPort($h['host'][0]);
        if ($hostName === null || !isset($this->hostAllow[$hostName])) {
            $this->reject($s, 421, 'host_not_allowed');
            return;
        }
        if (strtolower($h['upgrade'][0] ?? '') !== 'websocket') {
            $this->reject($s, 400, 'not_websocket_upgrade');
            return;
        }
        $connTokens = [];
        foreach ($h['connection'] ?? [] as $value) {
            foreach (explode(',', $value) as $token) {
                $connTokens[] = strtolower(trim($token));
            }
        }
        if (!in_array('upgrade', $connTokens, true)) {
            $this->reject($s, 400, 'missing_connection_upgrade');
            return;
        }
        $key = $h['sec-websocket-key'][0] ?? '';
        if (!WsHandshake::isValidKey($key)) {
            $this->reject($s, 400, 'bad_key');
            return;
        }
        if (($h['sec-websocket-version'][0] ?? '') !== '13') {
            $this->reject($s, 400, 'bad_version');
            return;
        }

        $origin = $h['origin'][0] ?? null;
        if ($origin === null) {
            if (!$spec->allowNoOrigin) {
                $this->reject($s, 403, 'origin_missing');
                return;
            }
        } elseif (!in_array(strtolower($origin), $spec->originAllow, true)) {
            $this->reject($s, 403, 'origin_not_allowed');
            return;
        }

        $protocol = $h['sec-websocket-protocol'][0] ?? null;
        $offered = [];
        if ($protocol !== null) {
            foreach (explode(',', $protocol) as $p) {
                $p = trim($p);
                if ($p === '' || preg_match("/^[!#$%&'*+\\-.^_\x60|~0-9A-Za-z]+$/", $p) !== 1) {
                    $this->reject($s, 400, 'bad_subprotocol');
                    return;
                }
                $offered[] = $p;
            }
        }
        $extensions = $h['sec-websocket-extensions'][0] ?? null;
        if ($extensions !== null && preg_match('/^[\x20-\x7e]{1,1024}$/', $extensions) !== 1) {
            $this->reject($s, 400, 'bad_extensions');
            return;
        }

        $policy = WsHandshake::applyPathPolicy($req['target'], $this->config->security->blockedPaths, $this->config->admin->pathPrefix);
        if (is_array($policy)) {
            $this->reject($s, $policy[0], $policy[1]);
            return;
        }
        $target = $policy;

        // Client address: only a trusted proxy may tell us who the real client is.
        if ($this->trusted->contains($s->peerIp)) {
            $trusted = $this->trusted;
            $header = $this->config->security->trustedProxyHeader;
            $clientIp = WsHandshake::deriveClientIp(
                $s->peerIp,
                $h[strtolower($header)] ?? [],
                $header,
                static fn (string $ip): bool => $trusted->contains($ip),
            );
            if (!$s->claimIp($clientIp, $spec->maxPerIp)) {
                $this->reject($s, 429, 'per_ip_limit');
                return;
            }
        }
        $clientIp = $s->claimedIp ?? $s->peerIp;

        try {
            $ip = $this->resolver->resolve($spec->targetHost, $spec->targetPort);
        } catch (\RuntimeException $e) {
            $this->log->warning('websocket target unavailable', ['listener' => $spec->id(), 'reason' => $e->getMessage()]);
            $this->reject($s, 502, 'target_unresolved');
            return;
        }

        $request = $this->buildOriginRequest($target, $key, $origin !== null, $protocol, $extensions, $h, $clientIp);
        $expectedAccept = WsHandshake::acceptFor($key);
        $ssl = $spec->targetUsesTls() ? TlsContext::client($spec, $this->config->origin->caBundle) : null;

        $s->connectTarget(
            $ip,
            $spec->targetPort,
            $ssl,
            function () use ($s, $request, $expectedAccept, $offered): void {
                $this->awaitOriginAnswer($s, $request, $expectedAccept, $offered);
            },
            function (string $reason) use ($s): void {
                $this->log->info('websocket target connect failed', ['listener' => $this->spec->id(), 'reason' => $reason]);
                $this->reject($s, 502, $reason);
            },
        );
    }

    /**
     * @param array<string,list<string>> $h
     */
    private function buildOriginRequest(string $target, string $key, bool $sendOrigin, ?string $protocol, ?string $extensions, array $h, string $clientIp): string
    {
        $spec = $this->spec;
        $defaultPort = $spec->targetScheme === 'wss' ? 443 : 80;
        $hostText = str_contains($spec->targetHost, ':') ? '[' . $spec->targetHost . ']' : $spec->targetHost;
        $host = $spec->hostOverride ?? ($spec->targetPort === $defaultPort ? $hostText : $hostText . ':' . $spec->targetPort);
        $lines = [
            'GET ' . $target . ' HTTP/1.1',
            'Host: ' . $host,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
        ];
        if ($sendOrigin) {
            $lines[] = 'Origin: ' . $this->config->origin->url;
        }
        if ($protocol !== null) {
            $lines[] = 'Sec-WebSocket-Protocol: ' . $protocol;
        }
        if ($extensions !== null) {
            $lines[] = 'Sec-WebSocket-Extensions: ' . $extensions;
        }
        if ($this->config->upstream->forwardCookies && isset($h['cookie'])) {
            $cookie = WsHandshake::filterCookies(implode('; ', $h['cookie']));
            if ($cookie !== '') {
                $lines[] = 'Cookie: ' . $cookie;
            }
        }
        if ($this->config->upstream->forwardAuthorization && isset($h['authorization'][0])) {
            $lines[] = 'Authorization: ' . $h['authorization'][0];
        }
        foreach (['user-agent' => 'User-Agent', 'accept-language' => 'Accept-Language'] as $lower => $canonical) {
            if (isset($h[$lower][0])) {
                $lines[] = $canonical . ': ' . $h[$lower][0];
            }
        }
        // Never append to a client supplied value: the origin sees exactly one address.
        $lines[] = 'X-Forwarded-For: ' . $clientIp;
        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * @param list<string> $offered subprotocols the client offered
     */
    private function awaitOriginAnswer(Session $s, string $request, string $expectedAccept, array $offered): void
    {
        $s->setPhase('upgrading');
        $target = $s->target();
        if ($target === null) {
            $s->close('no_target');
            return;
        }
        $maxHeader = $this->config->daemon->maxHeaderBytes;
        $buffer = '';
        $s->setTargetHandler(function (Session $s) use (&$buffer, $target, $maxHeader, $expectedAccept, $offered): void {
            for ($i = 0; $i < 4; $i++) {
                $data = $target->read(4096);
                if ($data === '') {
                    break;
                }
                $buffer .= $data;
                if (strlen($buffer) > $maxHeader + 4096) {
                    $this->reject($s, 502, 'origin_answer_too_large');
                    return;
                }
            }
            $end = strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                if ($target->eof) {
                    $this->reject($s, 502, 'origin_closed_early');
                } elseif (strlen($buffer) > $maxHeader) {
                    $this->reject($s, 502, 'origin_answer_too_large');
                }
                return;
            }
            $s->setTargetHandler(null);
            $this->relayAnswer($s, substr($buffer, 0, $end), substr($buffer, $end + 4), $expectedAccept, $offered);
        });
        $s->watchTarget();
        $s->queueToTarget($request);
    }

    /**
     * @param list<string> $offered
     */
    private function relayAnswer(Session $s, string $block, string $leftover, string $expectedAccept, array $offered): void
    {
        try {
            $resp = WsHandshake::parseResponse($block);
        } catch (\InvalidArgumentException) {
            $this->reject($s, 502, 'origin_answer_malformed');
            return;
        }
        $h = $resp['headers'];
        if ($resp['status'] !== 101) {
            $this->log->info('websocket origin refused the upgrade', ['listener' => $this->spec->id(), 'status' => $resp['status']]);
            $this->reject($s, 502, 'origin_refused_upgrade');
            return;
        }
        $accept = $h['sec-websocket-accept'][0] ?? '';
        if (count($h['sec-websocket-accept'] ?? []) !== 1 || !hash_equals($expectedAccept, $accept)) {
            $this->reject($s, 502, 'origin_bad_accept');
            return;
        }
        if (strtolower($h['upgrade'][0] ?? '') !== 'websocket') {
            $this->reject($s, 502, 'origin_bad_upgrade');
            return;
        }
        $connTokens = [];
        foreach ($h['connection'] ?? [] as $value) {
            foreach (explode(',', $value) as $token) {
                $connTokens[] = strtolower(trim($token));
            }
        }
        if (!in_array('upgrade', $connTokens, true)) {
            $this->reject($s, 502, 'origin_bad_connection');
            return;
        }

        // Allowlist, not denylist: only the headers the WebSocket protocol needs reach the client.
        $out = [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: ' . $expectedAccept,
        ];
        if (isset($h['sec-websocket-protocol'][0])) {
            $chosen = $h['sec-websocket-protocol'][0];
            if (!in_array($chosen, $offered, true)) {
                $this->reject($s, 502, 'origin_unoffered_subprotocol');
                return;
            }
            $out[] = 'Sec-WebSocket-Protocol: ' . $chosen;
        }
        if (isset($h['sec-websocket-extensions'][0])) {
            $ext = $h['sec-websocket-extensions'][0];
            if (preg_match('/^[\x20-\x7e]{1,1024}$/', $ext) !== 1) {
                $this->reject($s, 502, 'origin_bad_extensions');
                return;
            }
            $out[] = 'Sec-WebSocket-Extensions: ' . $ext;
        }
        $s->startPipe('', implode("\r\n", $out) . "\r\n\r\n" . $leftover);
    }

    private function reject(Session $s, int $status, string $reason): void
    {
        $text = self::STATUS_TEXT[$status] ?? 'Error';
        $this->log->debug('websocket handshake rejected', ['listener' => $this->spec->id(), 'peer' => $s->peerIp, 'status' => $status, 'reason' => $reason]);
        $s->sendAndClose(
            'HTTP/1.1 ' . $status . ' ' . $text . "\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            'ws_' . $reason,
        );
    }

    /** Lower-cased host name from a Host header value, or null when it is not a plain name. */
    private static function hostWithoutPort(string $value): ?string
    {
        $value = strtolower($value);
        if (preg_match('/^([a-z0-9.-]{1,253})(?::[0-9]{1,5})?$/', $value, $m) !== 1) {
            return null;
        }
        return rtrim($m[1], '.');
    }
}
