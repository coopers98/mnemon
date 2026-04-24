<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use Illuminate\Database\Seeder;

class ApiKeySeeder extends Seeder
{
    public function run(): void
    {
        $result = ApiKey::generate('Default Admin Key', ['*']);

        $this->command->info('API Key created: '.$result['key']);
        $this->command->warn('Save this key — it will not be shown again!');
    }
}
