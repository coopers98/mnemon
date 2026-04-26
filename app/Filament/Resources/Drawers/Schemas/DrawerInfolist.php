<?php

namespace App\Filament\Resources\Drawers\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DrawerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('content')
                    ->label('Content')
                    ->copyable()
                    ->fontFamily('mono')
                    ->columnSpanFull(),
                TextEntry::make('room.wing.name')
                    ->label('Wing'),
                TextEntry::make('room.name')
                    ->label('Room'),
                TextEntry::make('source')
                    ->placeholder('—'),
                TextEntry::make('tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'consolidated' => 'success',
                        'reviewed' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('raw'),
                TextEntry::make('retention_score')
                    ->label('Retention')
                    ->numeric(4)
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.2 => 'danger',
                        $state < 0.6 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—'),
                TextEntry::make('access_count')
                    ->label('Accessed')
                    ->numeric()
                    ->placeholder('0'),
                TextEntry::make('last_accessed_at')
                    ->label('Last accessed')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Updated')
                    ->dateTime(),
                TextEntry::make('deleted_at')
                    ->label('Deleted')
                    ->dateTime()
                    ->placeholder('—'),
                KeyValueEntry::make('metadata')
                    ->label('Metadata')
                    ->columnSpanFull(),
            ]);
    }
}
