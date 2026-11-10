<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Rewrite;

/**
 * Static kill-switch service worker served when SERVICE_WORKER_MODE=block.
 *
 * The script contains no configuration values and no request data. When a browser fetches the
 * worker URL to check for an update it receives this script, which clears every cache, unregisters
 * itself and reloads the controlled pages.
 */
final class ServiceWorkerKill
{
    public static function script(): string
    {
        return <<<'JS'
/* MirrorGate: service worker disabled by the site operator. */
self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    try {
      var names = await caches.keys();
      await Promise.all(names.map(function (name) { return caches.delete(name); }));
    } catch (e) {}
    try {
      await self.registration.unregister();
    } catch (e) {}
    try {
      var clients = await self.clients.matchAll({ type: 'window' });
      clients.forEach(function (client) {
        try { client.navigate(client.url); } catch (e) {}
      });
    } catch (e) {}
  })());
});
self.addEventListener('fetch', function () {});

JS;
    }
}
