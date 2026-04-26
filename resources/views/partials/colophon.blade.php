@props(['edition' => 'v0.4 · primer', 'active' => null])

<header class="colophon">
    <div class="frame colophon-inner">
        <a href="{{ route('landing') }}" class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            <span>Mnemon</span>
            <span class="dim" style="font-family:var(--mono);font-size:0.7rem;letter-spacing:0.14em;text-transform:uppercase;align-self:center;">{{ $edition }}</span>
        </a>
        <nav class="nav" aria-label="Primary">
            <a href="{{ route('landing') }}" class="{{ $active === 'overview' ? 'is-active' : '' }}">Overview</a>
            @auth
                <a href="{{ route('wiki.index') }}" class="{{ $active === 'wiki' ? 'is-active' : '' }}">Wiki</a>
                <a href="{{ route('palace.index') }}" class="{{ $active === 'palace' ? 'is-active' : '' }}">Palace</a>
            @else
                <a href="{{ route('landing') }}#architecture">Architecture</a>
                <a href="{{ route('landing') }}#palace">Palace</a>
            @endauth
            <a href="{{ route('landing') }}#specs">Spec sheet</a>
            <a href="{{ route('landing') }}#install">Self-host</a>
        </nav>
        <div class="nav-meta">
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
        </div>
    </div>
</header>
