<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — Mnemon</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center bg-slate-900 text-slate-200 antialiased">
    <div class="w-full max-w-sm rounded-lg border border-slate-700 bg-slate-800 p-8">
        <h1 class="mb-6 text-center text-2xl font-bold text-indigo-400">Mnemon</h1>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-700 bg-red-900/50 p-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-4">
                <label for="email" class="mb-1 block text-sm text-slate-400">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-md border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
            <div class="mb-4">
                <label for="password" class="mb-1 block text-sm text-slate-400">Password</label>
                <input type="password" id="password" name="password" required
                       class="w-full rounded-md border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
            <div class="mb-6 flex items-center gap-2">
                <input type="checkbox" id="remember" name="remember" class="rounded border-slate-600 bg-slate-700 text-indigo-500 focus:ring-indigo-500">
                <label for="remember" class="text-sm text-slate-400">Remember me</label>
            </div>
            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-slate-800">
                Sign in
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-slate-500">
            <a href="/" class="hover:text-slate-300">&larr; Back to home</a>
        </p>
    </div>
</body>
</html>
