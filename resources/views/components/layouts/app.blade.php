<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{ $title ?? 'Holds' }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Inter, "Helvetica Neue", Arial; background:#f8fafc; }
    .container { max-width: 720px; margin: 2rem auto; padding: 1rem; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
    .btn { padding: .6rem 1rem; border-radius: .5rem; background: #111; color:#fff; border:0; cursor:pointer; }
    .muted{ color:#6b7280; }
    a { color:#111; text-decoration: underline; }
    .row{display:flex; gap:12px; align-items:center;}
  </style>
</head>
<body>
  <div class="container">
    {{ $slot }}
  </div>
</body>
</html>
