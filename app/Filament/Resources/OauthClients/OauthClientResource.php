<?php

namespace App\Filament\Resources\OauthClients;

use App\Filament\Resources\OauthClients\Pages\ListOauthClients;
use App\Filament\Resources\OauthClients\Pages\ViewOauthClient;
use App\Filament\Resources\OauthClients\Tables\OauthClientsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Client;

class OauthClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $navigationLabel = 'OAuth Clients';

    protected static ?string $modelLabel = 'OAuth Client';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return OauthClientsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOauthClients::route('/'),
            'view' => ViewOauthClient::route('/{record}'),
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
