<?php

namespace App\Filament\Resources\Rooms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('drawers')
                ->withMax('drawers as last_activity_at', 'drawers.created_at'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('wing.name')
                    ->label('Wing')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('drawers_count')
                    ->label('Drawers')
                    ->numeric()
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('last_activity_at')
                    ->label('Last activity')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([
                SelectFilter::make('wing_id')
                    ->label('Wing')
                    ->relationship('wing', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
