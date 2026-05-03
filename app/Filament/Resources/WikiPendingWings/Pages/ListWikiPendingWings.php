<?php

namespace App\Filament\Resources\WikiPendingWings\Pages;

use App\Filament\Resources\WikiPendingWings\WikiPendingWingResource;
use Filament\Resources\Pages\ListRecords;

class ListWikiPendingWings extends ListRecords
{
    protected static string $resource = WikiPendingWingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
