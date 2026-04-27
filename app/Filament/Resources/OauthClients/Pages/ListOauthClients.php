<?php

namespace App\Filament\Resources\OauthClients\Pages;

use App\Filament\Resources\OauthClients\OauthClientResource;
use Filament\Resources\Pages\ListRecords;

class ListOauthClients extends ListRecords
{
    protected static string $resource = OauthClientResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
