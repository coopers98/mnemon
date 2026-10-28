<?php

namespace App\Filament\Resources\LlmModels;

use App\Filament\Resources\LlmModels\Pages\CreateLlmModel;
use App\Filament\Resources\LlmModels\Pages\EditLlmModel;
use App\Filament\Resources\LlmModels\Pages\ListLlmModels;
use App\Filament\Resources\LlmModels\Schemas\LlmModelForm;
use App\Filament\Resources\LlmModels\Tables\LlmModelsTable;
use App\Models\LlmModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LlmModelResource extends Resource
{
    protected static ?string $model = LlmModel::class;

    protected static ?string $modelLabel = 'LLM model';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    public static function form(Schema $schema): Schema
    {
        return LlmModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LlmModelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLlmModels::route('/'),
            'create' => CreateLlmModel::route('/create'),
            'edit' => EditLlmModel::route('/{record}/edit'),
        ];
    }
}
