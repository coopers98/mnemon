<?php

namespace App\Filament\Resources\Drawers\Schemas;

use App\Models\Room;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DrawerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('content')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull(),
                Select::make('room_id')
                    ->label('Room')
                    ->relationship('room', 'name', fn ($query) => $query->with('wing'))
                    ->getOptionLabelFromRecordUsing(fn (Room $room) => "{$room->wing->name} / {$room->name}")
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('source')
                    ->maxLength(255)
                    ->placeholder('e.g. email, slack, manual'),
                KeyValue::make('metadata')
                    ->keyLabel('Field')
                    ->valueLabel('Value')
                    ->reorderable()
                    ->columnSpanFull(),
            ]);
    }
}
