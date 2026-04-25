<?php

namespace App\Filament\Widgets;

use App\Models\Drawer;
use App\Models\WikiPage;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MnemonStatsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $drawerCount = Drawer::count();
        $wikiCount = WikiPage::count();

        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);
        $staleCount = WikiPage::where(function ($query) use ($staleThreshold) {
            $query->whereNull('last_compiled_at')
                ->orWhere('last_compiled_at', '<', $staleThreshold);
        })->count();

        $sevenDayChart = $this->buildSevenDayChart();
        $sevenDayCount = array_sum($sevenDayChart);

        [$lastWriteValue, $lastWriteSubtitle] = $this->buildLastWrite();

        return [
            Stat::make('Drawers', (string) $drawerCount)
                ->description('verbatim entries')
                ->descriptionIcon(Heroicon::OutlinedRectangleStack)
                ->color('primary'),

            Stat::make('Wiki pages', (string) $wikiCount)
                ->description($staleCount > 0 ? "{$staleCount} stale" : 'all fresh')
                ->descriptionIcon(Heroicon::OutlinedBookOpen)
                ->color($staleCount > 0 ? 'warning' : 'primary'),

            Stat::make('Drawers added (7d)', (string) $sevenDayCount)
                ->description('last 7 days')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('success')
                ->chart($sevenDayChart),

            Stat::make('Last write', $lastWriteValue)
                ->description($lastWriteSubtitle)
                ->descriptionIcon(Heroicon::OutlinedPencilSquare)
                ->color('gray'),
        ];
    }

    /**
     * Build a 7-element array of daily drawer-creation counts, oldest day first.
     *
     * @return array<int, int>
     */
    protected function buildSevenDayChart(): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = match ($driver) {
            'pgsql' => 'date(created_at)',
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default => 'DATE(created_at)',
        };

        $counts = Drawer::query()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw("$dateExpr as day, count(*) as count")
            ->groupBy('day')
            ->pluck('count', 'day');

        $chart = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $chart[] = (int) ($counts[$day] ?? 0);
        }

        return $chart;
    }

    /**
     * Determine the most recent write across drawers and wiki pages.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function buildLastWrite(): array
    {
        $latestDrawer = Drawer::with('room.wing')->orderByDesc('created_at')->first();
        $latestWiki = WikiPage::orderByDesc('updated_at')->first();

        if (! $latestDrawer && ! $latestWiki) {
            return ['Never', null];
        }

        $drawerTime = $latestDrawer?->created_at;
        $wikiTime = $latestWiki?->updated_at;

        $drawerWins = $drawerTime && (! $wikiTime || $drawerTime->greaterThanOrEqualTo($wikiTime));

        if ($drawerWins) {
            /** @var Carbon $drawerTime */
            $room = $latestDrawer->room;
            $wing = $room?->wing;
            $subtitle = $wing && $room
                ? "to '{$wing->name} / {$room->name}'"
                : 'to a drawer';

            return [$drawerTime->diffForHumans(), $subtitle];
        }

        /** @var Carbon $wikiTime */
        return [$wikiTime->diffForHumans(), "to '{$latestWiki->name}'"];
    }
}
