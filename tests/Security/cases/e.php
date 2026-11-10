<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Security probe cases owned by module E (security + error pages).
 *
 * Each case is [name, rawRequest, expectedStatusOrCallable]. The raw request is written to the
 * mirror socket as is; "{host}" is replaced by the mirror host:port the harness started.
 */
return [
    ['E: CRLF in a header value is rejected', "GET / HTTP/1.1\r\nHost: {host}\r\nX-Test: a\r\n b\r\nConnection: close\r\n\r\n", 400],
    ['E: duplicate Host header is rejected', "GET / HTTP/1.1\r\nHost: {host}\r\nHost: evil.example\r\nConnection: close\r\n\r\n", 400],
    ['E: Host outside MIRROR_URL answers 421', "GET / HTTP/1.1\r\nHost: evil.example\r\nConnection: close\r\n\r\n", 421],
    ['E: Content-Length plus Transfer-Encoding is rejected', "POST / HTTP/1.1\r\nHost: {host}\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n0\r\n\r\n", 400],
    ['E: WebSocket upgrade answers 501', "GET / HTTP/1.1\r\nHost: {host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n", 501],
    ['E: TRACE answers 405', "TRACE / HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n", 405],
    ['E: admin path without token is a generic 404', "GET /__mirrorgate/live HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n", 404],
    ['E: admin token in the query string is ignored', "GET /__mirrorgate/live?token=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n", 404],
];
