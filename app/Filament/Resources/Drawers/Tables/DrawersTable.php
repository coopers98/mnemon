<?php

namespace App\Filament\Resources\Drawers\Tables;

use App\Models\Drawer;
use App\Models\Wing;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class DrawersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('room.wing'))
            ->columns([
                TextColumn::make('content')
                    ->label('Content')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('room.wing.name')
                    ->label('Wing')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('room.name')
                    ->label('Room')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('source')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'consolidated' => 'success',
                        'reviewed' => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('wing_id')
                    ->label('Wing')
                    ->options(fn () => Wing::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas('room', fn (Builder $q) => $q->where('wing_id', $data['value']));
                    }),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(fn () => Cache::remember(
                        'mnemon.drawers.distinct_sources',
                        60,
                        fn () => Drawer::query()
                            ->whereNotNull('source')
                            ->distinct()
                            ->pluck('source', 'source')
                            ->all()
                    )),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date),
                            );
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
