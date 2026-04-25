<?php

namespace App\Filament\Resources\WikiPages\Schemas;

use App\Models\WikiPage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class WikiPageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label('Name')
                    ->size(TextSize::Large)
                    ->copyable(),
                TextEntry::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => WikiPage::typeBadgeColor($state)),
                TextEntry::make('description')
                    ->label('Description')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('word_count')
                    ->label('Word count')
                    ->numeric(),
                TextEntry::make('last_compiled_at')
                    ->label('Last compiled')
                    ->dateTime()
                    ->placeholder('Never compiled'),
                TextEntry::make('content')
                    ->label('Content')
                    ->markdown()
                    ->columnSpanFull(),
            ]);
    }
}
