<?php

namespace App\Filament\Resources\Wings\Pages;

use App\Filament\Resources\Wings\WingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWing extends EditRecord
{
    protected static string $resource = WingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
