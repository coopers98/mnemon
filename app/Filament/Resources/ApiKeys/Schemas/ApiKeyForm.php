<?php

namespace App\Filament\Resources\ApiKeys\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ApiKeyForm
{
    /**
     * Hardcoded scope catalog. The MCP server gates tool calls on these strings,
     * so any addition here needs to match what `ApiKey::hasScope()` consumers check.
     */
    public const SCOPE_OPTIONS = [
        '*' => 'Admin (full access)',
        'palace:read' => 'Read drawers',
        'palace:write' => 'Write drawers',
        'wiki:read' => 'Read wiki pages',
        'wiki:write' => 'Write wiki pages',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                CheckboxList::make('scopes')
                    ->required()
                    ->options(self::SCOPE_OPTIONS)
                    ->columns(2)
                    ->columnSpanFull(),
                TagsInput::make('wing_restrictions')
                    ->label('Wing restrictions')
                    ->placeholder('e.g. work, project:*')
                    ->helperText('Wing slugs or wildcard patterns (e.g. project:*). Leave empty for unrestricted.')
                    ->columnSpanFull(),
            ]);
    }
}
