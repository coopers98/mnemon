<?php

namespace App\Filament\Resources\WikiPages\Tables;

use App\Models\WikiPage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WikiPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->copyable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => WikiPage::typeBadgeColor($state))
                    ->sortable(),
                TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        'low' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('confidence_score')
                    ->label('Score')
                    ->numeric(2)
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.3 => 'danger',
                        $state < 0.6 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('quality_score')
                    ->label('Quality')
                    ->numeric(2)
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.4 => 'danger',
                        $state < 0.7 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pending_drawers_since_compile')
                    ->label('Pending')
                    ->numeric()
                    ->alignRight()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('word_count')
                    ->label('Words')
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('last_compiled_at')
                    ->label('Last compiled')
                    ->dateTime()
                    // SQLite treats NULL as the smallest value, so a never-compiled page
                    // lands last on the default DESC sort. PostgreSQL defaults to NULLS
                    // FIRST on DESC, which would float never-compiled pages to the top of
                    // the list. Spell the placement out so both engines agree that "never
                    // compiled" sorts as the oldest.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'wiki_pages.last_compiled_at '.($direction === 'desc' ? 'desc nulls last' : 'asc nulls first')
                    ))
                    ->placeholder('Never'),
            ])
            ->defaultSort('last_compiled_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(WikiPage::typeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
