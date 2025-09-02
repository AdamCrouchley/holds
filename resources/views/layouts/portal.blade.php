<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title ?? 'Customer Portal' }}</title>
  @vite(['resources/css/app.css','resources/js/app.js'])

  <style>
    :root {
      --brand: #0ea5e9;
      --brand-dark: #0284c7;
      --bg-gradient: linear-gradient(135deg, #f8fafc, #f1f5f9, #e2e8f0);
    }
    body {
      background: var(--bg-gradient);
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-start font-sans text-slate-800">

  {{-- Top navigation bar (subtle glass look) --}}
  <header class="w-full backdrop-blur-md bg-white/70 border-b border-slate-200/60 sticky top-0 z-20">
    <div class="max-w-6xl mx-auto flex items-center justify-between px-6 py-4">
      <div class="flex items-center gap-2">
        <div class="h-8 w-8 rounded-full bg-sky-500 flex items-center justify-center text-white font-bold">
          DD
        </div>
        <span class="text-lg font-semibold tracking-tight">Dream Drives Portal</span>
      </div>
      <nav class="hidden sm:flex gap-6 text-sm text-slate-600">
        <a href="{{ route('portal.login') }}" class="hover:text-sky-600">Login</a>
        <a href="{{ route('support.contact') }}" class="hover:text-sky-600">Support</a>
      </nav>
    </div>
  </header>

  {{-- Main content wrapper --}}
  <main class="flex-1 w-full flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-lg">
      @yield('content')
    </div>
  </main>

  {{-- Footer --}}
  <footer class="w-full py-8 text-center text-sm text-slate-500">
    <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
  </footer>

</body>
</html>
