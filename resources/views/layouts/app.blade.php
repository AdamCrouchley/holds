<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Holds' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Inter, "Helvetica Neue", Arial;
            background: #f8fafc;
            margin: 0;
        }
        .container {
            max-width: 720px;
            margin: 2rem auto;
            padding: 1rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
        }
        .btn {
            padding: .6rem 1rem;
            border-radius: .5rem;
            background: #111;
            color: #fff;
            border: 0;
            cursor: pointer;
        }
        .muted {
            color: #6b7280;
        }
        a {
            color: #111;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .row {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        /* Navbar */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        .navbar-left a {
            font-weight: 600;
            font-size: 1rem;
        }
        .navbar-right {
            display: flex;
            gap: 1rem;
        }
        .navbar-right a {
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            color: #111;
        }
        .navbar-right a:hover {
            background: #f3f4f6;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-left">
            <a href="{{ route('portal.magic') }}">Holds</a>
        </div>
        <div class="navbar-right">
            <a href="{{ route('admin.api-keys.index') }}">API Keys</a>
            <a href="{{ route('admin.automations.index') }}">Automations</a>
        </div>
    </nav>

    <div class="container">
        {{ $slot }}
    </div>
</body>
</html>
