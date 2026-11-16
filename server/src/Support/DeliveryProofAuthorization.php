<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Permission;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/** Revalidate the caller at the proof operation's serialization point. */
class DeliveryProofAuthorization
{
    /**
     * Keep post-lock authorization reads fresh under MySQL's default
     * repeatable-read transactions, without changing the pooled connection's
     * isolation level for any future transaction.
     */
    public function prepareLockedWrite(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Proof capture requires a top-level database transaction.');
        }
        DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function authorize(Order $order, Request $request): void
    {
        $original = Auth::getUserFromSession($request);
        $company  = session('company');
        if (!$original instanceof User || !is_string($company) || $company === '') {
            $this->unauthenticated($request);
        }
        if ($company !== $order->company_uuid) {
            $this->deny(403);
        }

        $fresh = $this->freshIdentity($request);
        if (!$fresh instanceof User || $fresh->uuid !== $original->uuid || $fresh->status !== 'active') {
            $this->unauthenticated($request);
        }
        if (session('company') !== $company) {
            $this->deny(403);
        }

        // Do not reuse the user/membership relations loaded before the lock wait.
        $membership = CompanyUser::query()
            ->where('company_uuid', $company)
            ->where('user_uuid', $fresh->uuid)
            ->where('status', 'active')
            ->first();
        if (!$membership) {
            $this->deny(403);
        }
        // The wildcard index is cached by principal id even across fresh model
        // instances. Invalidate only this member's in-process index.
        app(PermissionRegistrar::class)->forgetWildcardPermissionIndex($membership);
        $fresh->setRelation('companyUser', $membership);
        if (!$fresh->isAdmin()) {
            // Same explicit and wildcard permissions as Core Auth::can(), using
            // the fresh principal and fresh membership rather than the guard cache.
            $permissions = Permission::findByNames([
                'fleet-ops update order', 'fleet-ops * order', 'fleet-ops *',
            ]);
            if (!$permissions->contains(static fn ($permission) => $fresh->hasPermissionTo($permission))) {
                $this->deny(403);
            }
        }

        if ($fresh->type === 'driver' && !Driver::query()
            ->where('company_uuid', $company)
            ->where('user_uuid', $fresh->uuid)
            ->where('uuid', $order->driver_assigned_uuid)
            ->exists()) {
            $this->deny(404);
        }

        $request->setUserResolver(static fn () => $fresh);
    }

    protected function freshIdentity(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            // Core checks deleted/expired keys, Sanctum tokens, Navigator parents
            // and driver scope. A cloned request avoids its cached user resolver.
            $probe = clone $request;
            $probe->setUserResolver(static fn () => null);
            $result = $this->authenticateOnceWithBasic($probe);
            if ($result !== true) {
                $this->deny(401);
            }
            $fresh = $probe->user();
            if (!$fresh instanceof User) {
                return null;
            }
            $token = PersonalAccessToken::findToken($bearer);
            if ($token instanceof PersonalAccessToken) {
                // A token revoked or explicitly expired while the proof waited
                // for the SQL lock must not be reauthorized from cached auth.
                if ($token->expires_at && !$token->expires_at->isFuture()) {
                    $this->deny(401);
                }
                $expiration = config('sanctum.expiration');
                if ($expiration && (!$token->created_at || !$token->created_at->gt(now()->subMinutes($expiration)))) {
                    $this->deny(401);
                }
                if (is_callable(Sanctum::$accessTokenAuthenticationCallback)
                    && !call_user_func(Sanctum::$accessTokenAuthenticationCallback, $token, true)) {
                    $this->deny(401);
                }
                $fresh->withAccessToken($token);
            }

            return $fresh;
        }

        return $this->freshSessionIdentity($request);
    }

    /** Keep native authentication intact while testing revocation between reads. */
    protected function authenticateOnceWithBasic(Request $probe): mixed
    {
        return (new AuthenticateOnceWithBasicAuth())->authenticatedWithBasic($probe);
    }

    protected function freshSessionIdentity(Request $request): ?User
    {
        $guard = Auth::guard('web');
        if (!$guard instanceof SessionGuard || !$request->hasSession()) {
            return null;
        }
        $session = $request->session();
        if (!$session instanceof Store || !$session->isStarted()) {
            return null;
        }
        // Start a new Store to read persisted data, without cached attributes or
        // saving it. A destroyed/expired session must not retain its old login id.
        $persisted = clone $session;
        $persisted->flush();
        $persisted->start();
        $identifier = $persisted->get($guard->getName());
        if (!is_string($identifier) && !is_int($identifier)) {
            return null;
        }
        $fresh = $guard->getProvider()->retrieveById($identifier);
        if (!$fresh instanceof User) {
            return null;
        }
        $passwordHash = $persisted->get('password_hash_web');
        if ($passwordHash !== null && (!is_string($passwordHash) || !is_string($fresh->getAuthPassword()) || !hash_equals($fresh->getAuthPassword(), $passwordHash))) {
            return null;
        }
        $company = $persisted->get('company');
        if ($company !== null && $company !== session('company')) {
            return null;
        }

        return $fresh;
    }

    protected function unauthenticated(Request $request): never
    {
        // Prevent the outer session middleware from saving a cached login back
        // into a session that was destroyed while this request waited.
        if (!$request->bearerToken() && $request->hasSession() && $request->session() instanceof Store) {
            $request->session()->invalidate();
        }
        $this->deny(401);
    }

    protected function deny(int $status): never
    {
        throw new HttpResponseException(new JsonResponse(['error' => 'Proof is not authorized.'], $status));
    }
}
