{{--
    Logged-in identity plus the wing selection, shared by the auth-code consent
    and the device consent.

    The empty shape matters: "all wings" unchecked with nothing selected means
    deny-all, not unrestricted. That is the strictest thing the screen can say,
    so it must not be reachable by accident — the approve button is disabled
    while the selection is empty (see partials/wing-script).
--}}
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
        <p class="text-xs text-muted-foreground mt-2">
            Wing restrictions apply to palace content — drawers and drawer search.
            They do not apply to the wiki: this agent will be able to read every
            wiki page regardless of the selection above.
        </p>
    </div>
</div>
