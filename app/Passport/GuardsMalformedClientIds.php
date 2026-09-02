<?php

namespace App\Passport;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as BaseClientRepository;

/**
 * Rejects malformed client ids before they reach the database.
 *
 * `oauth_clients.id` is a native uuid column, and Passport's repository puts the
 * raw id straight into the query. On PostgreSQL a non-uuid literal is a database
 * error, and a QueryException is not a League OAuth exception — so
 * `HandlesOAuthErrors` cannot convert it, and the request becomes a 500 where
 * the spec calls for a 4xx.
 *
 * Three endpoints funnel through here: `/oauth/authorize` (tracked as D15),
 * the refresh_token grant on `/oauth/token`, and `/oauth/device/code`.
 *
 * SQLite is permissive enough to return null instead of throwing, which is why
 * the test suite never saw any of it.
 */
class GuardsMalformedClientIds extends BaseClientRepository
{
    public function find(string|int $id): ?Client
    {
        if (! $this->isUuid($id)) {
            return null;
        }

        return parent::find($id);
    }

    public function findActive(string|int $id): ?Client
    {
        if (! $this->isUuid($id)) {
            return null;
        }

        return parent::findActive($id);
    }

    private function isUuid(string|int $id): bool
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }
}
