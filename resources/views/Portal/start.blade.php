<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Find your booking</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Inter, Arial, sans-serif;background:#f8fafc}
    .wrap{max-width:560px;margin:40px auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px}
    input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .btn{background:#ef7e1a;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
    .muted{color:#475569}
    .alert{padding:10px 12px;border-radius:10px;margin-bottom:10px}
    .alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Find your booking</h1>
    <p class="muted">Enter your booking reference and the email used to book.</p>

    @if(session('error'))
      <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <form method="post" action="{{ route('portal.find') }}">
      @csrf
      <label>Reference</label>
      <input name="reference" value="{{ old('reference') }}" required>

      <div style="height:10px"></div>

      <label>Email</label>
      <input name="email" type="email" value="{{ old('email') }}" required>

      <div style="height:14px"></div>
      <button class="btn">Open my portal</button>
    </form>
  </div>
</body>
</html>
