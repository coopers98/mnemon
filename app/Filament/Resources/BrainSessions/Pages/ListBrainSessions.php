<?php

namespace App\Filament\Resources\BrainSessions\Pages;

use App\Filament\Resources\BrainSessions\BrainSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListBrainSessions extends ListRecords
{
    protected static string $resource = BrainSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
