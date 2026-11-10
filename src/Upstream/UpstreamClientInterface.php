<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Upstream;

interface UpstreamClientInterface
{
    /**
     * Performs one logical upstream fetch (possibly several attempts for
     * idempotent methods). Transport failures are reported through
     * UpstreamResult::$error and never thrown; the only exception is an
     * \MirrorGate\Http\HttpException(413) raised before any network I/O when
     * the declared request body length exceeds the configured limit.
     *
     * @throws \MirrorGate\Http\HttpException
     */
    public function fetch(UpstreamRequest $request, UpstreamSink $sink): UpstreamResult;
}
