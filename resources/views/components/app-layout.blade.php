{{-- resources/views/components/app-layout.blade.php --}}
@props(['title' => null])

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title ? "$title | " : '' }}Customer Portal</title>

  {{-- Vite (proper setup) --}}
  @vite(['resources/css/app.css','resources/js/app.js'])

  {{-- Fallback so it’s styled even if Vite isn’t running/built --}}
  @env(['local','development'])
  <script src="https://cdn.tailwindcss.com"></script>
  @endenv
</head>
<body class="antialiased bg-gray-100 text-gray-900">
  <div class="min-h-screen">
    {{ $header ?? '' }}
    {{ $slot }}
  </div>
</body>
</html>
