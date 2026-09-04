<?php

namespace App\Filament\Resources\OauthClients\Tables;

use App\Models\McpClientRestriction;
use App\Support\TokenRevoker;
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
                TextColumn::make('wing_restrictions')
                    ->label('Wings')
                    ->badge()
                    ->color('info')
                    ->getStateUsing(function (Client $record): array {
                        $restriction = McpClientRestriction::find((string) $record->id);

                        if ($restriction === null || $restriction->wing_patterns === null) {
                            return ['(unrestricted)'];
                        }

                        if ($restriction->wing_patterns === []) {
                            return ['(no wings — denied)'];
                        }

                        return $restriction->wing_patterns;
                    }),
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
                    ->modalDescription('Revokes the client and every access and refresh token issued to it. Nothing can be exchanged in its name afterwards.')
                    ->action(fn (Client $record) => TokenRevoker::client($record)),
            ])
            ->toolbarActions([]);
    }
}
