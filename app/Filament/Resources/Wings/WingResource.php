<?php

namespace App\Filament\Resources\Wings;

use App\Filament\Resources\Wings\Pages\CreateWing;
use App\Filament\Resources\Wings\Pages\EditWing;
use App\Filament\Resources\Wings\Pages\ListWings;
use App\Filament\Resources\Wings\Schemas\WingForm;
use App\Filament\Resources\Wings\Tables\WingsTable;
use App\Models\Wing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WingResource extends Resource
{
    protected static ?string $model = Wing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return WingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWings::route('/'),
            'create' => CreateWing::route('/create'),
            'edit' => EditWing::route('/{record}/edit'),
        ];
    }
}
