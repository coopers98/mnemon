<?php

namespace App\Filament\Resources\WikiPages\Pages;

use App\Filament\Resources\WikiPages\WikiPageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWikiPage extends ViewRecord
{
    protected static string $resource = WikiPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
