<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', 'Mnemon — The memory your AI deserves')</title>
    <meta name="description" content="@yield('meta_description', 'A self-hosted second brain for AI agents. Verbatim storage, hybrid retrieval, a living wiki — exposed over MCP.')" />
    <link rel="icon" href="{{ asset('favicon.ico') }}" />
    <link rel="stylesheet" href="{{ asset('styles/mnemon.css') }}" />
    @stack('head')
</head>
<body>
    @yield('body')
    @stack('scripts')
</body>
</html>
