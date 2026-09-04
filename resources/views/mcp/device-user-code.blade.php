@extends('mcp.layout')

@section('title', 'Connect a Device')

@section('card')
    @if (session('status') === 'authorization-approved')
        @include('mcp.partials.card-header', ['heading' => 'Device connected'])
        <div class="px-6 pb-6">
            <div class="rounded-lg border p-4 bg-muted/50 text-center">
                <p class="font-medium text-sm">Device authorized — you can close this tab.</p>
            </div>
        </div>
    @elseif (session('status') === 'authorization-denied')
        @include('mcp.partials.card-header', ['heading' => 'Authorization denied'])
        <div class="px-6 pb-6">
            <div class="rounded-lg border p-4 bg-muted/50 text-center">
                <p class="font-medium text-sm">Authorization denied. The device was not connected.</p>
            </div>
        </div>
    @else
        @include('mcp.partials.card-header', [
            'heading' => 'Connect a device',
            'subheading' => 'Enter the code shown on the device you are connecting.',
        ])

        {{-- GET on purpose: DeviceUserCodeController redirects ?user_code= to the
             consent screen, so the code travels in the URL the device printed. --}}
        <form method="GET" action="{{ route('passport.device') }}" class="px-6 pb-6 space-y-4">
            <div class="space-y-2">
                <input
                    type="text"
                    name="user_code"
                    value="{{ old('user_code') }}"
                    placeholder="BCDFGHJK"
                    autofocus
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    class="w-full rounded-md border border-input bg-background p-3 text-center font-mono text-lg tracking-widest uppercase focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                @error('user_code')
                    <p class="text-sm text-destructive text-center">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 w-full"
            >
                Continue
            </button>
        </form>
    @endif
@endsection
