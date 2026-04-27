<?php

namespace App\Filament\Resources\OauthClients\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Laravel\Passport\Client;

class OauthClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('redirect_uris')
                    ->label('Redirect URIs')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : ($state ?? '—'))
                    ->wrap()
                    ->limit(80),
                TextColumn::make('user.email')
                    ->label('Owner')
                    ->placeholder('(personal access)')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tokens_count')
                    ->label('Active Tokens')
                    ->counts('tokens')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('revoke')
                    ->label('Revoke All Tokens')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke all tokens for this client?')
                    ->modalDescription('This will revoke all access tokens issued to this client. The client itself will remain registered.')
                    ->action(fn (Client $record) => $record->tokens()->update(['revoked' => true])),
            ])
            ->toolbarActions([]);
    }
}
