<?php

namespace App\Filament\Resources\WikiPages\Schemas;

use App\Models\WikiPage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WikiPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Use `type:slug` form, e.g. `project:atlas`')
                    ->columnSpanFull(),
                Select::make('type')
                    ->required()
                    ->options(WikiPage::typeOptions())
                    ->native(false),
                TextInput::make('description')
                    ->maxLength(255),
                Textarea::make('content')
                    ->required()
                    ->rows(14)
                    ->helperText('Markdown supported')
                    ->columnSpanFull(),
            ]);
    }
}
