<?php

namespace App\Filament\Widgets;

use App\Models\BrainSession;
use Filament\Widgets\Widget;

class LiveMcpSessionsWidget extends Widget
{
    protected string $view = 'filament.widgets.live-mcp-sessions';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    public function getViewData(): array
    {
        $sessions = BrainSession::query()
            ->whereNotNull('access_token_id')
            ->latest('created_at')
            ->limit(5)
            ->get(['tool_name', 'source', 'created_at', 'result_count']);

        return ['sessions' => $sessions];
    }
}
