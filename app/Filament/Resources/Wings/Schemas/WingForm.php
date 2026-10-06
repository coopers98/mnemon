<?php

namespace App\Filament\Resources\Wings\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit')
                    ->helperText('Auto-generated from the name.'),
                Textarea::make('description')
                    ->rows(3),
                TagsInput::make('aliases')
                    ->placeholder('e.g. recital-lineup')
                    ->helperText('Other names this project goes by, such as its repository name. Captured sessions from a matching repository are filed in this wing.'),
            ]);
    }
}
