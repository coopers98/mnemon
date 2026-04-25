<?php

namespace App\Filament\Resources\Wings\Pages;

use App\Filament\Resources\Wings\WingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWings extends ListRecords
{
    protected static string $resource = WingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
