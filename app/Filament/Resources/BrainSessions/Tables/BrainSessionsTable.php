<?php

namespace App\Filament\Resources\BrainSessions\Tables;

use App\Models\BrainSession;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class BrainSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tool_name')
                    ->label('Tool')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('result_count')
                    ->label('Results')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tool_name')
                    ->label('Tool')
                    ->options(fn () => Cache::remember(
                        'mnemon.brain_sessions.distinct_tool_names',
                        60,
                        fn () => BrainSession::query()
                            ->whereNotNull('tool_name')
                            ->distinct()
                            ->orderBy('tool_name')
                            ->pluck('tool_name', 'tool_name')
                            ->all()
                    )),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(fn () => Cache::remember(
                        'mnemon.brain_sessions.distinct_sources',
                        60,
                        fn () => BrainSession::query()
                            ->whereNotNull('source')
                            ->distinct()
                            ->orderBy('source')
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
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
