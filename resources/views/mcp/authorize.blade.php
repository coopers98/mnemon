<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-background text-foreground">
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">
        <!-- Card Container -->
        <div class="rounded-lg border bg-card text-card-foreground shadow-sm">
            <!-- Header -->
            <div class="flex flex-col space-y-1.5 p-6 pb-4">
                <div class="flex items-center justify-center mb-4">
                    <!-- Shield Icon -->
                    <svg class="h-12 w-12 text-primary" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
                    Authorize {{ $client->name }}
                </h3>

                <p class="text-sm text-muted-foreground text-center mt-2">
                    Authorize {{ $client->name }} to access your Mnemon brain. Choose which wings this agent can see.
                </p>
            </div>

            <!-- Approve Form (wraps all content so checkboxes are submitted) -->
            <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">

                {{-- mcp:use is the single OAuth scope; submit it so Passport records the grant --}}
                <input type="hidden" name="scopes[]" value="mcp:use">

                <div class="px-6 pb-2 space-y-5">
                    <!-- User Info -->
                    <div class="rounded-lg border p-4 bg-muted/50">
                        <p class="text-sm text-muted-foreground mb-1">Logged in as:</p>
                        <p class="font-medium text-sm">{{ $user->email }}</p>
                    </div>

                    <!-- Wing Restrictions -->
                    <div class="space-y-2">
                        <p class="text-sm font-medium">Palace wing access:</p>
                        <div class="rounded-lg border divide-y">
                            <!-- All wings master toggle -->
                            <label class="flex items-center gap-3 p-3 cursor-pointer hover:bg-muted/30 transition-colors">
                                <input
                                    type="checkbox"
                                    name="all_wings"
                                    value="1"
                                    id="all-wings"
                                    checked
                                    class="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                                >
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium">All wings (no restriction)</span>
                                    <p class="text-xs text-muted-foreground mt-0.5">Access to all current and future palace wings</p>
                                </div>
                            </label>

                            @if($wings->isNotEmpty())
                                <!-- Per-wing checkboxes (disabled when all_wings is checked) -->
                                <div id="wing-list" class="divide-y opacity-40 pointer-events-none">
                                    @foreach($wings as $wing)
                                        <label class="flex items-center gap-3 p-3 pl-6 cursor-pointer hover:bg-muted/30 transition-colors">
                                            <input
                                                type="checkbox"
                                                name="wings[]"
                                                value="{{ $wing->slug }}"
                                                class="wing-checkbox h-4 w-4 rounded border-input text-primary focus:ring-primary"
                                                disabled
                                            >
                                            <div class="flex-1 min-w-0">
                                                <span class="text-sm">{{ $wing->name }}</span>
                                                @if($wing->description)
                                                    <p class="text-xs text-muted-foreground mt-0.5">{{ $wing->description }}</p>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <div class="p-3 pl-6">
                                    <p class="text-xs text-muted-foreground italic">No wings exist yet — all future wings will be accessible.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

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
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const allWingsCheckbox = document.getElementById('all-wings');
        const wingList = document.getElementById('wing-list');
        const wingCheckboxes = document.querySelectorAll('.wing-checkbox');

        // Toggle per-wing checkboxes when "all wings" is checked/unchecked
        function syncWingList() {
            if (!wingList) return;
            if (allWingsCheckbox.checked) {
                wingList.classList.add('opacity-40', 'pointer-events-none');
                wingCheckboxes.forEach(function (cb) {
                    cb.disabled = true;
                    cb.checked = false;
                });
            } else {
                wingList.classList.remove('opacity-40', 'pointer-events-none');
                wingCheckboxes.forEach(function (cb) {
                    cb.disabled = false;
                });
            }
        }

        allWingsCheckbox.addEventListener('change', syncWingList);
        syncWingList(); // run on page load

        // Authorize form: show loading state then watch for redirect
        var form = document.getElementById('authorizeForm');
        var button = document.getElementById('authorizeButton');
        var authorizeText = document.getElementById('authorizeText');
        var loadingSpinner = document.getElementById('loadingSpinner');

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
</body>
</html>
