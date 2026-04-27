<?php

namespace App\Filament\Resources\OauthAccessTokens;

use App\Filament\Resources\OauthAccessTokens\Pages\ListOauthAccessTokens;
use App\Filament\Resources\OauthAccessTokens\Tables\OauthAccessTokensTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Token;

class OauthAccessTokenResource extends Resource
{
    protected static ?string $model = Token::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $navigationLabel = 'Access Tokens';

    protected static ?string $modelLabel = 'Access Token';

    public static function table(Table $table): Table
    {
        return OauthAccessTokensTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('revoked', false)
            ->where('expires_at', '>', now());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOauthAccessTokens::route('/'),
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
