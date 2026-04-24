<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateKeyCommand extends Command
{
    protected $signature = 'mnemon:create-key {name} {--scopes=* : Scopes for this key} {--wings= : Comma-separated wing restrictions}';

    protected $description = 'Create an API key from the CLI';

    public function handle(): int
    {
        $name = $this->argument('name');
        $scopes = $this->option('scopes');
        $wingsOption = $this->option('wings');

        if (empty($scopes)) {
            $this->error('At least one scope is required. Use --scopes=scope1 --scopes=scope2 or --scopes=*');

            return self::FAILURE;
        }

        $wingRestrictions = null;
        if ($wingsOption) {
            $wingRestrictions = array_map('trim', explode(',', $wingsOption));
        }

        $result = ApiKey::generate($name, $scopes, $wingRestrictions);

        $this->newLine();
        $this->info('✓ API key created successfully!');
        $this->newLine();
        $this->line('Name: '.$name);
        $this->line('Scopes: '.implode(', ', $scopes));
        if ($wingRestrictions) {
            $this->line('Wing Restrictions: '.implode(', ', $wingRestrictions));
        } else {
            $this->line('Wing Restrictions: (none — unrestricted)');
        }
        $this->newLine();
        $this->warn('⚠ IMPORTANT: Copy this key now. It will never be shown again!');
        $this->newLine();
        $this->line('Key: '.$result['key']);
        $this->newLine();

        return self::SUCCESS;
    }
}
