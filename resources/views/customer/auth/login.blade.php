<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customer Login</title>
  <style>
    body{font-family:system-ui,Inter,Arial;background:#f8fafc}
    .wrap{max-width:520px;margin:60px auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px}
    label{display:block;margin:10px 0 4px;color:#334155}
    input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .btn{margin-top:14px;background:#ef7e1a;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer;width:100%}
    .err{color:#b91c1c;margin-top:6px}
    .muted{color:#64748b}
  </style>
</head>
<body>
  <div class="wrap">
    <h1 style="margin:0 0 8px">Sign in</h1>
    <p class="muted" style="margin:0 0 18px">Use your email and your reservation reference (e.g. <code>QW1756187813</code>).</p>

    @if ($errors->any())
      <div class="err">
        <ul>
          @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
      </div>
    @endif

    <form method="post" action="{{ route('customer.login.attempt') }}">
      @csrf
      <label for="email">Email address</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required>

      <label for="reference">Reservation reference</label>
      <input id="reference" name="reference" type="text" value="{{ old('reference') }}" required>

      <button class="btn">Sign in</button>
    </form>
  </div>
</body>
</html>
