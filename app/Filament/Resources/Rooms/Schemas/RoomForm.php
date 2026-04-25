<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('wing_id')
                    ->label('Wing')
                    ->relationship('wing', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit')
                    ->helperText('Auto-generated from the name.'),
            ]);
    }
}
