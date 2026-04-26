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
                TextEntry::make('confidence')
                    ->label('Confidence')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        'low' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextEntry::make('confidence_score')
                    ->label('Confidence Score')
                    ->numeric(4)
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.3 => 'danger',
                        $state < 0.6 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—'),
                TextEntry::make('quality_score')
                    ->label('Quality Score')
                    ->numeric(4)
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.4 => 'danger',
                        $state < 0.7 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—'),
                TextEntry::make('source_count')
                    ->label('Source count')
                    ->numeric()
                    ->placeholder('0'),
                TextEntry::make('revision_count')
                    ->label('Revisions')
                    ->numeric()
                    ->placeholder('1'),
                TextEntry::make('pending_drawers_since_compile')
                    ->label('Pending drawers')
                    ->numeric()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextEntry::make('word_count')
                    ->label('Word count')
                    ->numeric(),
                TextEntry::make('last_compiled_at')
                    ->label('Last compiled')
                    ->dateTime()
                    ->placeholder('Never compiled'),
                TextEntry::make('last_accessed_at')
                    ->label('Last accessed')
                    ->dateTime()
                    ->placeholder('Never accessed'),
                TextEntry::make('sources')
                    ->label('Source Drawers')
                    ->placeholder('None')
                    ->columnSpanFull()
                    ->formatStateUsing(function ($state, $record) {
                        if (empty($record->sources)) {
                            return '—';
                        }

                        return collect($record->sources)
                            ->map(fn ($id) => "Drawer #{$id}")
                            ->join(', ');
                    }),
                TextEntry::make('related')
                    ->label('Related Pages')
                    ->placeholder('None')
                    ->columnSpanFull()
                    ->formatStateUsing(function ($state, $record) {
                        if (empty($record->related)) {
                            return '—';
                        }

                        return collect($record->related)->join(', ');
                    }),
                TextEntry::make('graph_connections')
                    ->label('Graph Connections')
                    ->placeholder('None')
                    ->columnSpanFull()
                    ->formatStateUsing(function ($state, $record) {
                        $outgoing = $record->outgoingRelationships()->get();
                        $incoming = $record->incomingRelationships()->get();

                        if ($outgoing->isEmpty() && $incoming->isEmpty()) {
                            return '—';
                        }

                        $lines = [];
                        foreach ($outgoing as $rel) {
                            $lines[] = "→ [{$rel->edge_type}] {$rel->to_page}";
                        }
                        foreach ($incoming as $rel) {
                            $lines[] = "← [{$rel->edge_type}] {$rel->from_page}";
                        }

                        return implode("\n", $lines);
                    }),
                TextEntry::make('content')
                    ->label('Content')
                    ->markdown()
                    ->columnSpanFull(),
            ]);
    }
}
