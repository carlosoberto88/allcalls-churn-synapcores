<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * Contract for SynapCores authentication strategies.
 *
 * Each strategy is responsible for adding the appropriate Authorization header
 * (or any other credentials) to a PendingRequest before it is dispatched.
 */
interface AuthStrategy
{
    /**
     * Apply authentication credentials to the given PendingRequest and return it.
     *
     * Implementations must not mutate state on the strategy itself in a
     * thread-unsafe way; the returned PendingRequest carries all modifications.
     */
    public function applyTo(PendingRequest $request): PendingRequest;

    /**
     * Invalidate any cached credentials so the next applyTo() fetches fresh ones.
     *
     * For ApiKeyAuth this is a no-op.  For JwtAuth this clears the token cache.
     */
    public function invalidate(): void;
}
