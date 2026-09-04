@extends('mcp.layout')

@section('title', 'Authorize Application')

@section('card')
    @include('mcp.partials.card-header', [
        'heading' => 'Authorize '.$client->name,
        'subheading' => 'Authorize '.$client->name.' to access your Mnemon brain. Choose which palace wings this agent can read and write.',
    ])

    <!-- Approve Form (wraps all content so checkboxes are submitted) -->
    <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        {{-- mcp:use is the single OAuth scope; submit it so Passport records the grant --}}
        <input type="hidden" name="scopes[]" value="mcp:use">

        @include('mcp.partials.wing-picker')

        <!-- Footer With Buttons -->
        <div class="flex items-center p-6 pt-4 gap-3">
            <!-- Deny triggers the hidden deny form -->
            <div class="flex-1">
                <button
                    type="button"
                    id="denyButton"
                    class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 w-full"
                >
                    <svg class="mr-2 h-4 w-4" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Cancel
                </button>
            </div>

            <!-- Approve Submit -->
            <div class="flex-1">
                <button
                    type="submit"
                    id="authorizeButton"
                    class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 w-full"
                >
                    <span id="authorizeText">Authorize</span>
                    <svg id="loadingSpinner" class="animate-spin ml-2 h-4 w-4 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>
            </div>
        </div>
    </form>

    <!-- Hidden deny form (uses DELETE method spoofing) -->
    <form method="POST" action="{{ route('passport.authorizations.deny') }}" id="denyForm" class="hidden">
        @csrf
        @method('DELETE')
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
    </form>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('authorizeForm');
        var button = document.getElementById('authorizeButton');
        var authorizeText = document.getElementById('authorizeText');
        var loadingSpinner = document.getElementById('loadingSpinner');

        @include('mcp.partials.wing-script')

        // This screen is opened by an agent and is expected to close itself once
        // the grant lands. The device screen deliberately does none of this: the
        // person navigated there themselves and stays to read the result.
        form.addEventListener('submit', function () {
            button.disabled = true;
            authorizeText.textContent = 'Authorizing...';
            loadingSpinner.classList.remove('hidden');

            setTimeout(function () {
                var checkRedirect = setInterval(function () {
                    if (!window.location.href.includes('/oauth/authorize') ||
                        window.location.search.includes('code=') ||
                        window.location.search.includes('error=')) {
                        clearInterval(checkRedirect);
                        window.close();
                    }
                }, 100);

                // Fallback: close after five seconds
                setTimeout(function () {
                    clearInterval(checkRedirect);
                    window.close();
                }, 5000);
            }, 200);
        });

        // Deny button submits the hidden deny form
        document.getElementById('denyButton').addEventListener('click', function () {
            setTimeout(function () { window.close(); }, 200);
            document.getElementById('denyForm').submit();
        });
    });
</script>
@endsection
