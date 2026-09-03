<?php

namespace App\Filament\Resources\OauthAccessTokens\Tables;

use App\Models\McpTokenRestriction;
use App\Models\User;
use App\Support\TokenRevoker;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Laravel\Passport\Token;

class OauthAccessTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner_email')
                    ->label('Owner')
                    ->placeholder('(no user)')
                    ->getStateUsing(function (Token $record): ?string {
                        if (! $record->user_id) {
                            return null;
                        }

                        return User::find($record->user_id)?->email;
                    }),
                TextColumn::make('scopes')
                    ->label('Scopes')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : ($state ?? '*'))
                    ->placeholder('(all)'),
                TextColumn::make('wing_restrictions')
                    ->label('Wing Restrictions')
                    ->badge()
                    ->color('info')
                    ->getStateUsing(function (Token $record): array {
                        $restriction = McpTokenRestriction::find($record->id);

                        if ($restriction === null || $restriction->wing_patterns === null) {
                            return ['(unrestricted)'];
                        }

                        return $restriction->wing_patterns;
                    }),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last Used')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke this access token?')
                    ->modalDescription('The client will lose access immediately and will need to re-authorize.')
                    ->action(fn (Token $record) => TokenRevoker::token($record)),
            ])
            ->toolbarActions([]);
    }
}
