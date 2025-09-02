<!-- resources/views/customer/bookings/index.blade.php -->
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Bookings</title>
  <style>
    body{font-family:system-ui,Inter,Arial;background:#f8fafc;margin:0}
    .wrap{max-width:960px;margin:40px auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}
    .row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .btn{background:#ef7e1a;color:#fff;border:none;border-radius:10px;padding:8px 12px;cursor:pointer}
    .muted{color:#475569}
    .alert{padding:10px;border-radius:10px;margin-bottom:10px}
    .ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .claim{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}
    input{padding:8px;border:1px solid #cbd5e1;border-radius:10px}
    a.btn-link{background:#0ea5e9;color:#fff;text-decoration:none;padding:6px 10px;border-radius:10px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="row">
      <h1 style="margin:0;">
        Welcome, {{ $customer->first_name ?: ($customer->full_name ?: $customer->email) }}
      </h1>
      <form method="post" action="{{ route('customer.logout') }}">
        @csrf
        <button class="btn" type="submit">Log out</button>
      </form>
    </div>

    @if(session('claim_ok'))
      <div class="alert ok">{{ session('claim_ok') }}</div>
    @endif
    @if(session('claim_error'))
      <div class="alert err">{{ session('claim_error') }}</div>
    @endif

    <h2>My Bookings</h2>

    @if($bookings->isEmpty())
      <p class="muted">No bookings found for your account.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Reference</th>
            <th>Vehicle</th>
            <th>Start</th>
            <th>End</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        @foreach($bookings as $b)
          <tr>
            <td>{{ $b->reference }}</td>
            <td>{{ $b->car_label }}</td>
            <td>{{ optional($b->start_at)->format('Y-m-d H:i') }}</td>
            <td>{{ optional($b->end_at)->format('Y-m-d H:i') }}</td>
            <td>{{ $b->total_formatted }}</td>
            <td>
              <a class="btn-link" href="{{ $b->portal_url }}">Open portal</a>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif

    <h3 style="margin-top:24px">Claim a booking</h3>
    <form class="claim" method="post" action="{{ route('customer.claim') }}">
      @csrf
      <label for="ref">Reference</label>
      <input id="ref" name="reference" placeholder="e.g. QW1756187813" required>
      <button class="btn" type="submit">Claim</button>
    </form>
    <p class="muted">Use this if an older booking isn’t attached to your account.</p>
  </div>
</body>
</html>
