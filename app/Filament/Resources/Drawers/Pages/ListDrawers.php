<?php

namespace App\Filament\Resources\Drawers\Pages;

use App\Filament\Resources\Drawers\DrawerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDrawers extends ListRecords
{
    protected static string $resource = DrawerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
