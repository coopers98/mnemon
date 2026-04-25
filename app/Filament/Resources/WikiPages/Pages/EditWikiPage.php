<?php

namespace App\Filament\Resources\WikiPages\Pages;

use App\Filament\Resources\WikiPages\WikiPageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWikiPage extends EditRecord
{
    protected static string $resource = WikiPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Bump last_compiled_at when meaningful content (name, type, or content) actually
     * changed, so the page doesn't immediately appear stale after a manual edit.
     * Description-only edits and no-op saves leave the staleness clock alone. The MCP
     * context_set tool is the other writer of this column.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $original = $this->record->getOriginal();

        foreach (['name', 'type', 'content'] as $key) {
            if (($data[$key] ?? null) !== ($original[$key] ?? null)) {
                $data['last_compiled_at'] = now();
                break;
            }
        }

        return $data;
    }
}
