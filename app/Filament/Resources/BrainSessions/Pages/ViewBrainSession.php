<?php

namespace App\Filament\Resources\BrainSessions\Pages;

use App\Filament\Resources\BrainSessions\BrainSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBrainSession extends ViewRecord
{
    protected static string $resource = BrainSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
