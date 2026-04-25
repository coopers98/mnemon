<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Drawers\DrawerResource;
use App\Filament\Resources\WikiPages\WikiPageResource;
use App\Models\Wing;
use App\Services\PalaceSearchService;
use App\Services\WikiSearchService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Search extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $slug = 'search';

    protected static ?string $title = 'Search';

    /**
     * Sort to the bottom of the sidebar so the navigation entries
     * created by resources stay near the top.
     */
    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.search';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [
        'q' => '',
        'scope' => 'all',
        'wing' => null,
    ];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('q')
                    ->label('Search')
                    ->placeholder('Type to search…')
                    ->autofocus()
                    ->live(debounce: 400)
                    ->columnSpan(6),
                Select::make('scope')
                    ->label('Scope')
                    ->options([
                        'all' => 'Everything',
                        'palace' => 'Palace (drawers)',
                        'wiki' => 'Wiki pages',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->live()
                    ->columnSpan(3),
                Select::make('wing')
                    ->label('Wing (palace only)')
                    ->options(fn () => Wing::orderBy('name')->pluck('name', 'slug')->all())
                    ->placeholder('All wings')
                    ->visible(fn (Get $get) => in_array($get('scope'), ['all', 'palace'], true))
                    ->columnSpan(3),
            ])
            ->columns(12)
            ->statePath('data');
    }

    /**
     * Build a flat, score-sorted list of search results combining
     * palace and wiki sources.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getResults(): Collection
    {
        $q = (string) ($this->data['q'] ?? '');
        $scope = (string) ($this->data['scope'] ?? 'all');
        $wing = $this->data['wing'] ?? null;
        $wing = is_string($wing) && $wing !== '' ? $wing : null;

        if (trim($q) === '') {
            return collect();
        }

        $palace = collect();
        $wiki = collect();

        if (in_array($scope, ['all', 'palace'], true)) {
            $palace = app(PalaceSearchService::class)
                ->search($q, wing: $wing, limit: 20, mode: 'hybrid');
        }

        if (in_array($scope, ['all', 'wiki'], true)) {
            $wiki = app(WikiSearchService::class)
                ->search($q, limit: 20);
        }

        // For scope=all, each service normalizes scores within its own
        // result set, so a top palace score and a top wiki score are not
        // directly comparable. Re-normalize each collection against its
        // own max so the merged sort is apples-to-apples (still 0..1
        // within each source, but on a shared scale relative to that
        // source's best hit).
        if ($scope === 'all') {
            $normalize = function (Collection $rows): Collection {
                $max = $rows->max('score') ?: 1.0;

                return $rows->map(fn ($row) => tap($row, fn ($r) => $r->score = $r->score / $max));
            };

            $palace = $normalize($palace);
            $wiki = $normalize($wiki);
        }

        return $palace
            ->map(fn ($row) => $this->normalizeDrawer($row))
            ->concat($wiki->map(fn ($row) => $this->normalizeWiki($row)))
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeDrawer(object $row): array
    {
        $wingName = $row->wing ?? '';
        $roomName = $row->room ?? '';
        $wingSlug = $row->wing_slug ?? '';
        $roomSlug = $row->room_slug ?? '';

        return [
            'kind' => 'drawer',
            'id' => $row->id,
            'title' => trim("{$wingName} / {$roomName}", ' /'),
            'snippet' => Str::limit(strip_tags((string) ($row->content ?? '')), 240),
            'location' => trim("{$wingSlug}/{$roomSlug}", '/'),
            'score' => (float) ($row->score ?? 0.0),
            'url' => DrawerResource::getUrl('view', ['record' => $row->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeWiki(object $row): array
    {
        return [
            'kind' => 'wiki_page',
            'id' => $row->id,
            'title' => $row->name ?? '(unnamed)',
            'snippet' => Str::limit(strip_tags((string) ($row->content ?? '')), 240),
            'location' => 'wiki/'.($row->name ?? ''),
            'score' => (float) ($row->score ?? 0.0),
            'url' => WikiPageResource::getUrl('view', ['record' => $row->id]),
        ];
    }
}
