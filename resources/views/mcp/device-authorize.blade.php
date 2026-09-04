@extends('mcp.layout')

@section('title', 'Authorize Device')

@section('card')
    @include('mcp.partials.card-header', [
        'heading' => 'Authorize device: '.$client->name,
    ])

    <div class="px-6 -mt-2 pb-2 space-y-2">
        <p class="text-sm text-muted-foreground text-center">
            Code being approved:
            <span class="font-mono font-medium tracking-widest">{{ $request->query('user_code') }}</span>
        </p>
        <p class="text-sm text-muted-foreground text-center">
            A device just asked for this code. If you didn't run mnemon-authorize on
            one of your machines a moment ago, deny this.
        </p>
    </div>

    {{-- No client_id field: the server takes the client from the device code in
         the session, which is the same place its approve controller reads it.
         A client_id in the form would be a second, forgeable source of truth. --}}
    <form method="POST" action="{{ route('passport.device.authorizations.approve') }}" id="authorizeForm">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        @include('mcp.partials.wing-picker')

        <div class="flex items-center p-6 pt-4 gap-3">
            <div class="flex-1">
                <button
                    type="button"
                    id="denyButton"
                    class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 w-full"
                >
                    <svg class="mr-2 h-4 w-4" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Deny
                </button>
            </div>

            <div class="flex-1">
                <button
                    type="submit"
                    id="authorizeButton"
                    class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 w-full"
                >
                    Authorize
                </button>
            </div>
        </div>
    </form>

    {{-- Deny uses DELETE method spoofing, which is why the consent-capture
         middleware's POST match never fires on it. --}}
    <form method="POST" action="{{ route('passport.device.authorizations.deny') }}" id="denyForm" class="hidden">
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
    </form>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('authorizeButton');

        @include('mcp.partials.wing-script')

        // No window.close() choreography here, unlike the auth-code screen: the
        // person navigated to this page themselves and stays on it to read the
        // approved/denied result the redirect brings back.
        document.getElementById('denyButton').addEventListener('click', function () {
            document.getElementById('denyForm').submit();
        });
    });
</script>
@endsection
