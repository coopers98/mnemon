<?php

namespace App\Filament\Resources\ApiKeys\Tables;

use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApiKeysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('scopes')
                    ->badge()
                    ->separator(',')
                    ->placeholder('—'),
                TextColumn::make('wing_restrictions')
                    ->label('Wings')
                    ->badge()
                    ->separator(',')
                    ->placeholder('—'),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Revoked' : 'Active')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active')
                    ->falseLabel('Revoked')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('revoked_at'),
                        false: fn (Builder $query) => $query->whereNotNull('revoked_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Revoking immediately blocks this key. This cannot be undone.')
                    ->visible(fn (ApiKey $record): bool => ! $record->isRevoked())
                    ->action(fn (ApiKey $record) => $record->revoke()),
            ]);
    }
}
