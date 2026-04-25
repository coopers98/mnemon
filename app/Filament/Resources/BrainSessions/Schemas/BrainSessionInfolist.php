<?php

namespace App\Filament\Resources\BrainSessions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class BrainSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('tool_name')
                    ->label('Tool')
                    ->badge()
                    ->size(TextSize::Large),
                TextEntry::make('source')
                    ->label('Source')
                    ->placeholder('—'),
                TextEntry::make('result_count')
                    ->label('Result count')
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i:s'),
                TextEntry::make('input')
                    ->label('Input')
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '—')
                    ->fontFamily('mono')
                    ->copyable()
                    ->columnSpanFull(),
            ]);
    }
}
