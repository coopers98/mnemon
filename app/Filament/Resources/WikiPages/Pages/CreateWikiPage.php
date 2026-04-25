<?php

namespace App\Filament\Resources\WikiPages\Pages;

use App\Filament\Resources\WikiPages\WikiPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWikiPage extends CreateRecord
{
    protected static string $resource = WikiPageResource::class;

    /**
     * Stamp last_compiled_at on initial creation so a brand-new page isn't
     * immediately flagged as stale by the wiki staleness check.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['last_compiled_at'] = now();

        return $data;
    }
}
