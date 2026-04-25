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
