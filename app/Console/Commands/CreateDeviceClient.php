<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Provisions the per-device OAuth client for the device authorization grant.
 *
 * `passport:client --device` already does the underlying work, but it prompts,
 * prints only the id and secret, and enforces no naming convention. This is the
 * one step of enrolment that still happens over SSH, so it should hand the
 * operator everything the device needs and nothing they have to look up.
 */
class CreateDeviceClient extends Command
{
    protected $signature = 'mnemon:device-client {device : Short device name, e.g. thinkpad}';

    protected $description = 'Create the per-device OAuth client for the device authorization grant';

    public function handle(ClientRepository $clients): int
    {
        $name = 'claude-code@'.$this->argument('device');

        // One client per device is what makes revocation a per-device kill
        // switch. Reusing a name would quietly point two machines at one client,
        // and revoking either would cut off both.
        if (Client::query()->where('name', $name)->where('revoked', false)->exists()) {
            $this->error("A client named {$name} already exists.");
            $this->line('Revoke it in Filament (OAuth Clients → revoke) first if this device is being re-provisioned.');

            return self::FAILURE;
        }

        // Confidential, with exactly the device_code and refresh_token grants.
        $client = $clients->createDeviceAuthorizationGrantClient($name);

        $this->info("Created device client {$name}");
        $this->newLine();
        $this->line('  client_id:     '.$client->id);
        $this->line('  client_secret: '.$client->plainSecret);
        $this->newLine();
        $this->line('The secret is shown once and stored hashed. On the device, run the');
        $this->line("mnemon plugin's enrolment script and paste the secret when prompted:");
        $this->newLine();
        // sort -V, not head -1: the plugin cache keeps every installed version
        // side by side, so the lexically first directory is the OLDEST one --
        // and lexical order is wrong for versions regardless (0.10.0 < 0.9.0).
        $this->line('  bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | sort -V | tail -1)scripts/mnemon-authorize.sh" \\');
        $this->line('       '.rtrim((string) config('app.url'), '/').' '.$client->id);

        return self::SUCCESS;
    }
}
