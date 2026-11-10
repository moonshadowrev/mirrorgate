<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace MirrorGate\Daemon;

/**
 * Builds stream context option arrays for the TLS legs of a forwarder.
 */
final class TlsContext
{
    /**
     * Options for the listening side. Clients are not authenticated.
     *
     * @return array{ssl:array<string,mixed>}
     */
    public static function server(ListenerSpec $spec): array
    {
        $ssl = [
            'local_cert' => (string) $spec->tlsCert,
            'local_pk' => (string) $spec->tlsKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
            'disable_compression' => true,
            'honor_cipher_order' => true,
        ];
        if ($spec->alpn !== []) {
            $ssl['alpn_protocols'] = implode(',', $spec->alpn);
        }
        return ['ssl' => $ssl];
    }

    /**
     * Options for connecting to a TLS target. Verification cannot be disabled.
     *
     * @return array{ssl:array<string,mixed>}
     */
    public static function client(ListenerSpec $spec, ?string $caBundle): array
    {
        $peer = $spec->sni ?? $spec->targetHost;
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peer,
            'disable_compression' => true,
            'capture_peer_cert' => false,
        ];
        if ($caBundle !== null && $caBundle !== '' && is_file($caBundle)) {
            $ssl['cafile'] = $caBundle;
        }
        // The listener-side alpn option describes what clients may offer us; towards a gRPC origin we always ask for h2.
        if ($spec->kind === ListenerKind::Grpc) {
            $ssl['alpn_protocols'] = 'h2';
        }
        return ['ssl' => $ssl];
    }

    public static function serverMethod(): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
    }

    public static function clientMethod(): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
}
