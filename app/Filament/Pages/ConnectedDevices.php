<?php

namespace App\Filament\Pages;

use App\Services\ConnectedDeviceReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * What is connected to this instance, and when did it last call in.
 *
 * Read-only on purpose: revoking access belongs to OAuth Clients and OAuth
 * Access Tokens, which already do it. This page answers the question those
 * two cannot — whether a credential is actually being *used*.
 */
class ConnectedDevices extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $slug = 'connected-devices';

    protected static ?string $title = 'Connected Devices';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.connected-devices';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getDevices(): Collection
    {
        return app(ConnectedDeviceReport::class)->rows();
    }

    public function getActiveDays(): int
    {
        return ConnectedDeviceReport::ACTIVE_DAYS;
    }

    public function getStaleDays(): int
    {
        return ConnectedDeviceReport::STALE_DAYS;
    }
}
