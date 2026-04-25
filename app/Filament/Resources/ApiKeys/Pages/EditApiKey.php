<?php

namespace App\Filament\Resources\ApiKeys\Pages;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditApiKey extends EditRecord
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * No DeleteAction — keys are revoked, not deleted. The header carries a Revoke
     * action that mirrors the table's row action.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revoke')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Revoking immediately blocks this key. This cannot be undone.')
                ->visible(fn (): bool => $this->record instanceof ApiKey && ! $this->record->isRevoked())
                ->action(fn () => $this->record->revoke()),
        ];
    }
}
