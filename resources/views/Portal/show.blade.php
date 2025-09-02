<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Booking Portal – {{ $booking->reference }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @vite(['resources/css/app.css']) {{-- if you have it --}}
  <style>
    body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Inter,Arial,sans-serif;background:#f8fafc;color:#0f172a}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px}
    .grid{display:grid;gap:16px}
    .btn{display:inline-block;background:#ef7e1a;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
    .btn[disabled]{opacity:.5;cursor:not-allowed}
    .muted{color:#475569}
    .badge{display:inline-block;padding:.15rem .5rem;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.8rem}
    .money{font-weight:600}
    input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .col{flex:1 1 260px}
    .wrap{max-width:980px;margin:24px auto;padding:0 16px}
  </style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:1.6rem;font-weight:700;margin-bottom:8px;">Booking {{ $booking->reference }}</h1>
    <p class="muted" style="margin-top:0">
      @if($booking->customer_real_name)
        {{ $booking->customer_real_name }}
      @elseif($booking->customer?->email)
        {{ $booking->customer->email }}
      @endif
      <span class="badge" style="margin-left:.5rem">{{ ucfirst($booking->status ?? 'pending') }}</span>
    </p>

    @if(session('ok'))
      <div class="card" style="border-color:#22c55e;background:#f0fdf4;color:#166534;">{{ session('ok') }}</div>
    @endif
    @if(session('error'))
      <div class="card" style="border-color:#f87171;background:#fef2f2;color:#991b1b;">{{ session('error') }}</div>
    @endif

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-top:12px;">
      <div class="card">
        <h2 style="margin:0 0 8px 0;font-size:1.1rem;">Details</h2>
        <div class="muted">Vehicle</div>
        <div>{{ $booking->car_label ?: '—' }}</div>
        <div class="muted" style="margin-top:8px;">Start</div>
        <div>{{ optional($booking->start_at)->format('Y-m-d H:i') }}</div>
        <div class="muted" style="margin-top:8px;">End</div>
        <div>{{ optional($booking->end_at)->format('Y-m-d H:i') }}</div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 8px 0;font-size:1.1rem;">Money</h2>

        <div class="row">
          <div class="col">
            <div class="muted">Total</div>
            <div class="money">{{ $money((int)($booking->total_amount ?? 0)) }}</div>
          </div>
          <div class="col">
            <div class="muted">Paid so far</div>
            <div class="money">{{ $money((int)($booking->amount_paid ?? 0)) }}</div>
          </div>
          <div class="col">
            <div class="muted">Balance due</div>
            <div class="money">{{ $money($balanceDue) }}</div>
          </div>
        </div>

        <hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0">

        <div class="row">
          <div class="col">
            <div class="muted">Deposit required</div>
            <div class="money">{{ $money($depositRequired) }}</div>
          </div>
          <div class="col">
            <div class="muted">Deposit paid</div>
            <div class="money">{{ $money($depositPaid) }}</div>
          </div>
          <div class="col">
            <div class="muted">Deposit outstanding</div>
            <div class="money">{{ $money($depositDue) }}</div>
          </div>
        </div>

        <div class="muted" style="margin-top:12px;">Security hold</div>
        <div class="money">{{ $money((int)($booking->hold_amount ?? 0)) }}</div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 8px 0;font-size:1.1rem;">Deposit payment</h2>

        @if($depositDue <= 0)
          <p class="muted">Thanks — your deposit is fully paid.</p>
        @else
          <form method="post" action="{{ route('portal.pay.submit', $booking->portal_token) }}">
            @csrf
            <label class="muted">Amount (NZD)</label>
            <input name="amount" type="number" step="0.01" min="0.01"
                   value="{{ number_format($depositDue/100, 2, '.', '') }}">

            <div class="row" style="margin-top:8px;">
              <div class="col">
                <label class="muted">Method</label>
                <select name="method">
                  <option value="">— Select —</option>
                  <option value="card">Credit/Debit Card</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="cash">Cash</option>
                  <option value="stripe">Stripe</option>
                  <option value="paypal">PayPal</option>
                  <option value="manual">Manual</option>
                  <option value="other">Other</option>
                </select>
              </div>
            </div>

            <label class="muted" style="margin-top:8px;">Note</label>
            <textarea name="note" rows="2" placeholder="Reference, last 4 digits, etc."></textarea>

            <button class="btn" style="margin-top:10px;">Record deposit</button>
          </form>
          <p class="muted" style="margin-top:8px;">
            (This records a payment in our system. Online card checkout can be added later if you want Stripe.)
          </p>
        @endif
      </div>
    </div>

    <p class="muted" style="margin-top:14px;">
      Having trouble? Email us and include your reference <strong>{{ $booking->reference }}</strong>.
    </p>
  </div>
</body>
</html>
