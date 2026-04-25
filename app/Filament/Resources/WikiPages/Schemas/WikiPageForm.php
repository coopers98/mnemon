<?php

namespace App\Filament\Resources\WikiPages\Schemas;

use App\Models\WikiPage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                Select::make('confidence')
                    ->options(array_combine(WikiPage::CONFIDENCE_LEVELS, array_map('ucfirst', WikiPage::CONFIDENCE_LEVELS)))
                    ->native(false)
                    ->placeholder('Not set'),
                TagsInput::make('related')
                    ->placeholder('Add wiki page names')
                    ->helperText('Related wiki page names (e.g. project:atlas)'),
                Textarea::make('content')
                    ->required()
                    ->rows(14)
                    ->helperText('Markdown supported')
                    ->columnSpanFull(),
            ]);
    }
}
