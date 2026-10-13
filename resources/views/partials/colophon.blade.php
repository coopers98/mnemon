@props(['edition' => 'v0.4 · primer', 'active' => null])

@demo
    <div role="note" style="background:var(--ink);color:var(--paper);font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;text-align:center;padding:0.5rem 16px;">
        Demo · a read-only sample brain.
        <a href="{{ route('landing') }}#install" style="color:inherit;border-bottom:1px solid currentColor;">Run your own →</a>
    </div>
@enddemo
<header class="colophon">
    <div class="frame colophon-inner">
        <a href="{{ route('landing') }}" class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            <span>Mnemon</span>
            <span class="dim" style="font-family:var(--mono);font-size:0.7rem;letter-spacing:0.14em;text-transform:uppercase;align-self:center;">{{ $edition }}</span>
        </a>
        <nav class="nav" aria-label="Primary">
            <a href="{{ route('landing') }}" class="{{ $active === 'overview' ? 'is-active' : '' }}">Overview</a>
            @browsable
                <a href="{{ route('wiki.index') }}" class="{{ $active === 'wiki' ? 'is-active' : '' }}">Wiki</a>
                <a href="{{ route('palace.index') }}" class="{{ $active === 'palace' ? 'is-active' : '' }}">Palace</a>
            @else
                <a href="{{ route('landing') }}#palace">Palace</a>
            @endbrowsable
            <a href="{{ route('explain.how-it-works') }}" class="{{ $active === 'how-it-works' ? 'is-active' : '' }}">How it works</a>
            <a href="{{ route('landing') }}#specs">Spec sheet</a>
            <a href="{{ route('landing') }}#install">Self-host</a>
            @if (! config('mnemon.demo.enabled') && config('mnemon.demo.url'))
                <a href="{{ config('mnemon.demo.url') }}">Demo</a>
            @endif
        </nav>
        <div class="nav-meta">
            @demo
                <span>MIT · read-only demo</span>
            @else
                @auth
                    <span><span class="rubric">●</span> {{ auth()->user()->name }}</span>
                    <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn-bare">log out</button>
                    </form>
                @else
                    <span>MIT · self-hosted</span>
                    <a class="btn-bare" href="{{ route('login') }}">sign in</a>
                @endauth
            @enddemo
        </div>
    </div>
</header>
