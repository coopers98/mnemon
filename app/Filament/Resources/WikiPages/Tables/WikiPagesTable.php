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
                    ->sortable()
                    ->placeholder('Never'),
            ])
            // Verified empirically: modern SQLite (3.50+) and PostgreSQL both place NULLs
            // last on a DESC sort, so never-compiled pages naturally fall to the bottom.
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
