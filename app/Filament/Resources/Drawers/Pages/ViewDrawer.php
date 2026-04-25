<?php

namespace App\Filament\Resources\Drawers\Pages;

use App\Filament\Resources\Drawers\DrawerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDrawer extends ViewRecord
{
    protected static string $resource = DrawerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
