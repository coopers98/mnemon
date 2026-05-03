<?php

namespace App\Filament\Resources\WikiPendingWings;

use App\Filament\Resources\WikiPendingWings\Pages\ListWikiPendingWings;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class WikiPendingWingResource extends Resource
{
    protected static ?string $model = WikiPendingWing::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $navigationLabel = 'Pending Wings';

    protected static ?string $modelLabel = 'Pending Wing';

    protected static ?string $pluralModelLabel = 'Pending Wings';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', 'pending');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('wing_slug')->searchable(),
                TextColumn::make('wing_name')->searchable(),
                TextColumn::make('rationale')->wrap()->limit(100),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (WikiPendingWing $record) => self::approve($record)),
                Action::make('reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (WikiPendingWing $record) => self::reject($record)),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWikiPendingWings::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function approve(WikiPendingWing $row): void
    {
        if ($row->status !== 'pending') {
            return;
        }

        DB::transaction(function () use ($row) {
            $wing = Wing::firstOrCreate(
                ['slug' => $row->wing_slug],
                ['name' => $row->wing_name]
            );

            $payload = $row->drawer_payload;
            $room = Room::firstOrCreate(
                ['wing_id' => $wing->id, 'slug' => $payload['room_slug']],
                [
                    'name' => Str::headline($payload['room_slug']),
                    'metadata' => ['auto_created' => true, 'created_via' => 'session_digest'],
                ]
            );

            Drawer::create([
                'room_id' => $room->id,
                'content' => $payload['content'],
                'source' => $payload['source'] ?? 'session_digest',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $row->update(['status' => 'approved', 'decided_at' => now()]);
        });
    }

    protected static function reject(WikiPendingWing $row): void
    {
        if ($row->status !== 'pending') {
            return;
        }
        $row->update(['status' => 'rejected', 'decided_at' => now()]);
    }
}
