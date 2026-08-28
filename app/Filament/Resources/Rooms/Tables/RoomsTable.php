<?php

namespace App\Filament\Resources\Rooms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    // `last_activity_at` is MAX(drawers.created_at) and is NULL for a
                    // room that has never held a drawer. PostgreSQL puts NULLs first on
                    // DESC; SQLite puts them last. A room with no drawers is not the
                    // most recently active one, so pin NULLs to the "oldest" end on both
                    // engines. Postgres will not accept a select alias inside an ORDER BY
                    // expression, so NULLS FIRST/LAST is the only portable spelling here.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'last_activity_at '.($direction === 'desc' ? 'desc nulls last' : 'asc nulls first')
                    ))
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
