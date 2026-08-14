<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Admin Panel') - {{ config('app.name', 'Matchday Africa') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- Additional Admin Styles -->
    <style>
        .admin-sidebar {
            min-height: calc(100vh - 4rem);
        }
        .admin-content {
            min-height: calc(100vh - 4rem);
        }
    </style>
</head>
<body class="font-sans antialiased matchday-shell matchday-admin bg-gray-100">
    <!-- Admin Navigation -->
    <x-admin.navigation />

    <div class="flex">
        <!-- Sidebar (Optional - can be added later) -->
        <div class="hidden lg:block w-64 bg-gray-900 text-white admin-sidebar">
            <!-- Sidebar content can be added here if needed -->
        </div>

        <!-- Main Content -->
        <div class="flex-1 admin-content">
            <!-- Page Header -->
            @hasSection('header')
                <div class="matchday-page-header bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        @yield('header')
                    </div>
                </div>
            @endif

            <!-- Flash Messages -->
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 mx-4 mt-4 rounded">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 mx-4 mt-4 rounded">
                    {{ session('error') }}
                </div>
            @endif

            @if(session('warning'))
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 mx-4 mt-4 rounded">
                    {{ session('warning') }}
                </div>
            @endif

            @if(session('info'))
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 mx-4 mt-4 rounded">
                    {{ session('info') }}
                </div>
            @endif

            <!-- Page Content -->
            <main class="py-6">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <!-- Footer -->
    <footer class="matchday-admin-footer bg-gray-800 text-white py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="matchday-wordmark" aria-label="Matchday Africa home">
                <span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span>
            </a>
            <p>&copy; {{ date('Y') }} Matchday Africa · Command Desk</p>
        </div>
    </footer>

    <!-- Real-time Updates -->
    <x-realtime-updates />
</body>
</html>
