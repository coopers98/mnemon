{{-- Shield icon plus heading/subheading, shared by all three consent screens. --}}
<div class="flex flex-col space-y-1.5 p-6 pb-4">
    <div class="flex items-center justify-center mb-4">
        <!-- Shield Icon -->
        <svg class="h-12 w-12 text-primary" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
        </svg>
    </div>

    <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">{{ $heading }}</h3>

    @isset($subheading)
        <p class="text-sm text-muted-foreground text-center mt-2">{{ $subheading }}</p>
    @endisset

    {{ $slot ?? '' }}
</div>
