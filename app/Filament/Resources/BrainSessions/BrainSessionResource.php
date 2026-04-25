<?php

namespace App\Filament\Resources\BrainSessions;

use App\Filament\Resources\BrainSessions\Pages\ListBrainSessions;
use App\Filament\Resources\BrainSessions\Pages\ViewBrainSession;
use App\Filament\Resources\BrainSessions\Schemas\BrainSessionInfolist;
use App\Filament\Resources\BrainSessions\Tables\BrainSessionsTable;
use App\Models\BrainSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BrainSessionResource extends Resource
{
    protected static ?string $model = BrainSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'tool_name';

    public static function infolist(Schema $schema): Schema
    {
        return BrainSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrainSessionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrainSessions::route('/'),
            'view' => ViewBrainSession::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
