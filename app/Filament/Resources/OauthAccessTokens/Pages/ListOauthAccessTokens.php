<?php

namespace App\Filament\Resources\OauthAccessTokens\Pages;

use App\Filament\Resources\OauthAccessTokens\OauthAccessTokenResource;
use Filament\Resources\Pages\ListRecords;

class ListOauthAccessTokens extends ListRecords
{
    protected static string $resource = OauthAccessTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
