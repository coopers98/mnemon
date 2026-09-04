<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Passport;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Replaces Passport's own passport:purge.
 *
 * Same name deliberately. A separate mnemon:purge would only protect the people
 * who knew to use it, and the failure this guards against is precisely someone
 * later adding the obvious command to a schedule to tidy the table.
 *
 * The difference is one clause: an access token is not deleted while a refresh
 * token that is still valid points at it.
 *
 * Why that matters here. oauth_refresh_tokens has no client_id — a refresh token
 * is tied to a client only through oauth_access_tokens.client_id. Access tokens
 * live an hour and refresh tokens live ninety days, so the stock command deletes
 * the access token about a week in and leaves the refresh token orphaned, with
 * nothing left to attribute it to. TokenRevoker::client walks that same link, so
 * from then on revoking a client cannot revoke its refresh tokens, and the
 * client's own revoked flag becomes the single thing stopping the device.
 *
 * Refresh tokens no longer rotate (each exchange issues one and does not revoke
 * the last), so a device accumulates them steadily — this is not a rare row.
 *
 * The cost is that access-token rows now live as long as the refresh tokens
 * referencing them rather than a week. That is storage, and the alternative is
 * losing a revocation path.
 */
#[AsCommand(name: 'passport:purge')]
class PurgeTokens extends Command
{
    protected $signature = 'passport:purge
                            {--revoked : Only purge revoked tokens and authentication codes}
                            {--expired : Only purge expired tokens and authentication codes}
                            {--hours=168 : The number of hours to retain expired tokens}';

    protected $description = 'Purge revoked and / or expired tokens and authentication codes, keeping any access token a live refresh token still needs';

    public function handle(): void
    {
        $revoked = $this->option('revoked') || ! $this->option('expired');

        $expired = $this->option('expired') || ! $this->option('revoked')
            ? Date::now()->subHours((int) $this->option('hours'))
            : false;

        $constraint = fn (Builder $query): Builder => $query
            ->when($revoked, fn () => $query->orWhere('revoked', true))
            ->when($expired, fn () => $query->orWhere('expires_at', '<', $expired));

        // Refresh tokens first, so an access token whose only referent was a
        // dead refresh token is collectable in this run rather than the next.
        Passport::refreshToken()->newQuery()->where($constraint)->delete();

        $protected = Passport::token()->newQuery()
            ->where($constraint)
            ->whereHas('refreshToken', $this->stillLive(...))
            ->count();

        Passport::token()->newQuery()
            ->where($constraint)
            ->whereDoesntHave('refreshToken', $this->stillLive(...))
            ->delete();

        Passport::authCode()->newQuery()->where($constraint)->delete();

        if (Passport::$deviceCodeGrantEnabled) {
            Passport::deviceCode()->newQuery()->where($constraint)->delete();
        }

        $this->components->info(sprintf('Purged %s.', implode(' and ', array_filter([
            $revoked ? 'revoked items' : null,
            $expired ? "items expired for more than {$expired->longAbsoluteDiffForHumans()}" : null,
        ]))));

        // Said out loud, so the difference from stock Passport is observable
        // rather than a silent divergence someone discovers by reading source.
        if ($protected > 0) {
            $this->components->info(sprintf(
                'Kept %d expired access token%s still referenced by a live refresh token (deleting them would orphan the refresh tokens beyond revocation).',
                $protected,
                $protected === 1 ? '' : 's',
            ));
        }
    }

    private function stillLive(Builder $query): void
    {
        $query->where('revoked', false)->where('expires_at', '>', Date::now());
    }
}
