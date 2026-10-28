<?php

namespace App\Filament\Resources\LlmModels\Tables;

use App\Models\LlmModel;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LlmModelsTable
{
    public static function configure(Table $table): Table
    {
        $usage = fn (LlmModel $record) => $record->usageTotals();

        return $table
            ->columns([
                TextColumn::make('provider')
                    ->sortable(),
                TextColumn::make('model')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                IconColumn::make('is_digest_model')
                    ->label('Digests')
                    ->boolean(),
                TextColumn::make('price_in_per_mtok')
                    ->label('In $/1M')
                    ->placeholder('—')
                    ->alignRight(),
                TextColumn::make('price_cached_in_per_mtok')
                    ->label('Cached $/1M')
                    ->placeholder('—')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('price_out_per_mtok')
                    ->label('Out $/1M')
                    ->placeholder('—')
                    ->alignRight(),
                TextColumn::make('calls')
                    ->label('Calls')
                    ->state(fn (LlmModel $record) => $usage($record)->calls)
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('avg_in')
                    ->label('Avg in / call')
                    ->state(fn (LlmModel $record) => ($u = $usage($record))->calls ? intdiv($u->prompt_tokens, $u->calls) : null)
                    ->numeric()
                    ->placeholder('—')
                    ->alignRight(),
                TextColumn::make('avg_out')
                    ->label('Avg out / call')
                    ->state(fn (LlmModel $record) => ($u = $usage($record))->calls ? intdiv($u->completion_tokens, $u->calls) : null)
                    ->numeric()
                    ->placeholder('—')
                    ->alignRight(),
                TextColumn::make('spend')
                    ->label('Spend')
                    ->state(function (LlmModel $record) use ($usage) {
                        $u = $usage($record);
                        if (! $u->calls) {
                            return null;
                        }
                        $cost = $record->costOf($u->prompt_tokens, $u->cached_prompt_tokens, $u->completion_tokens);

                        // Cents hide a trial's spend; small totals keep four places.
                        return $cost === null ? 'no price' : '$'.number_format($cost, $cost < 1 ? 4 : 2);
                    })
                    ->placeholder('—')
                    ->alignRight(),
            ])
            ->defaultSort('model')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
