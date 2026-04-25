<?php

namespace App\Filament\Resources\Drawers;

use App\Filament\Resources\Drawers\Pages\CreateDrawer;
use App\Filament\Resources\Drawers\Pages\EditDrawer;
use App\Filament\Resources\Drawers\Pages\ListDrawers;
use App\Filament\Resources\Drawers\Pages\ViewDrawer;
use App\Filament\Resources\Drawers\Schemas\DrawerForm;
use App\Filament\Resources\Drawers\Schemas\DrawerInfolist;
use App\Filament\Resources\Drawers\Tables\DrawersTable;
use App\Models\Drawer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DrawerResource extends Resource
{
    protected static ?string $model = Drawer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DrawerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DrawerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DrawersTable::configure($table);
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
            'index' => ListDrawers::route('/'),
            'create' => CreateDrawer::route('/create'),
            'view' => ViewDrawer::route('/{record}'),
            'edit' => EditDrawer::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
