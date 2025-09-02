<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Page not found</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen grid place-items-center bg-gray-50 text-gray-800">
  <div class="text-center">
    <h1 class="text-3xl font-semibold mb-2">404 — Page not found</h1>
    <p class="text-gray-600 mb-6">The page you’re looking for doesn’t exist.</p>
    <a href="{{ route('home') }}" class="px-4 py-2 rounded bg-gray-900 text-white hover:bg-black">Go home</a>
  </div>
</body>
</html>
