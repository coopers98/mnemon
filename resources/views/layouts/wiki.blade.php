<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Mnemon' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-900 text-slate-200 antialiased">
    {{-- Header --}}
    <header class="fixed top-0 z-30 w-full border-b border-slate-700 bg-slate-800/95 backdrop-blur">
        <div class="flex h-14 items-center justify-between px-4">
            <div class="flex items-center gap-4">
                <a href="{{ route('wiki.index') }}" class="text-lg font-bold text-indigo-400 hover:text-indigo-300">
                    Mnemon
                </a>
                <nav class="hidden items-center gap-3 text-sm md:flex">
                    <a href="{{ route('wiki.index') }}" class="rounded px-2 py-1 hover:bg-slate-700 {{ request()->routeIs('wiki.*') ? 'bg-slate-700 text-white' : 'text-slate-400' }}">Wiki</a>
                    <a href="{{ route('palace.index') }}" class="rounded px-2 py-1 hover:bg-slate-700 {{ request()->routeIs('palace.*') ? 'bg-slate-700 text-white' : 'text-slate-400' }}">Palace</a>
                    <a href="/admin" class="rounded px-2 py-1 text-slate-400 hover:bg-slate-700">Admin</a>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <form action="{{ route('wiki.search') }}" method="GET" class="hidden md:block">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search..."
                           class="w-56 rounded-md border border-slate-600 bg-slate-700 px-3 py-1.5 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </form>
                @auth
                    <span class="hidden text-sm text-slate-400 md:inline">{{ auth()->user()->name }}</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-sm text-slate-400 hover:text-white">Logout</button>
                    </form>
                @endauth
            </div>
        </div>
    </header>

    <div class="flex pt-14">
        {{-- Sidebar --}}
        @hasSection('sidebar')
            <aside class="fixed left-0 top-14 hidden h-[calc(100vh-3.5rem)] w-56 overflow-y-auto border-r border-slate-700 bg-slate-800 p-4 lg:block">
                @yield('sidebar')
            </aside>
            <main class="min-h-[calc(100vh-3.5rem)] flex-1 p-6 lg:ml-56">
                @yield('content')
            </main>
        @else
            <main class="min-h-[calc(100vh-3.5rem)] flex-1 p-6">
                @yield('content')
            </main>
        @endif
    </div>
</body>
</html>
