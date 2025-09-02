@props(['title' => null])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? "$title | " : '' }}Customer Portal</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="antialiased bg-gray-100 text-gray-900">
    <div class="min-h-screen flex items-center justify-center p-6">
        {{ $slot }}
    </div>
</body>
</html>
