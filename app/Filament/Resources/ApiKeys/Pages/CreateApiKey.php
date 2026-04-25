<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiKey extends CreateRecord
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * Override the standard create flow so we can route through `ApiKey::generate()` —
     * which mints the plaintext, hashes it, and returns the plaintext alongside the
     * persisted model. The plaintext is shown once via a persistent notification and
     * never stored anywhere else.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $result = ApiKey::generate(
            $data['name'],
            $data['scopes'] ?? [],
            ! empty($data['wing_restrictions']) ? $data['wing_restrictions'] : null,
        );

        Notification::make()
            ->title('API key created')
            ->success()
            ->persistent()
            ->body("Plaintext key: {$result['key']}\n\nThis key will not be shown again — copy it now.")
            ->actions([
                Action::make('copy')
                    ->label('Copy key')
                    ->button()
                    ->extraAttributes([
                        'x-on:click' => 'window.navigator.clipboard.writeText('.json_encode($result['key']).')',
                    ]),
            ])
            ->send();

        return $result['model'];
    }
}
