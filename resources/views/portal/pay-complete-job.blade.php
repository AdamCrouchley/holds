{{-- resources/views/portal/pay-complete-job.blade.php --}}
@php
/** @var \App\Models\Job $job */
/** @var string|null $clientSecret */
/** @var string|null $setupSecret */

use Illuminate\Support\Carbon;

$pk = config('services.stripe.key');

/* ----------------------------- Dates / TZ ----------------------------- */
$tz    = $job->timezone ?? config('app.timezone', 'UTC');
$start = $job->start_at ? Carbon::parse($job->start_at)->timezone($tz) : null;
$end   = $job->end_at   ? Carbon::parse($job->end_at)->timezone($tz)   : null;

$duration = null;
if ($start && $end && $end->greaterThan($start)) {
    $mins = $end->diffInMinutes($start);
    $d = intdiv($mins, 60*24);
    $h = intdiv($mins % (60*24), 60);
    $m = $mins % 60;
    $parts = [];
    if ($d) $parts[] = $d.'d';
    if ($h) $parts[] = $h.'h';
    if (!$d && !$h && $m) $parts[] = $m.'m';
    $duration = implode(' ', $parts);
}
$tzShort = $start?->format('T') ?? $end?->format('T') ?? strtoupper($tz);

/* --------------------------- Money helpers --------------------------- */
$currency = strtoupper($job->currency ?? optional($job->flow)->currency ?? 'NZD');

$formatMoney = function (int $cents, string $cur) {
    $amount = number_format($cents / 100, 2);
    $sym = match (strtoupper($cur)) {
        'NZD' => 'NZ$', 'AUD' => 'A$', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', default => ''
    };
    return $sym ? ($sym.$amount) : ($amount.' '.strtoupper($cur));
};

/* ---------------------- Totals (DB-accurate first) ------------------- */
$totalCents = (int) ($job->charge_amount_cents ?? $job->charge_amount ?? 0);

/** Prefer summing payments with succeeded/captured status; fallback to cached column. */
try {
    $paidCents = method_exists($job, 'payments')
        ? (int) $job->payments()->whereIn('status', ['succeeded', 'captured'])->sum('amount_cents')
        : (int) ($job->paid_amount_cents ?? $job->amount_paid_cents ?? 0);
} catch (\Throwable $e) {
    $paidCents = (int) ($job->paid_amount_cents ?? $job->amount_paid_cents ?? 0);
}

$remainingCents = max(0, $totalCents - $paidCents);
$holdCents      = (int) (optional($job->flow)->hold_amount_cents ?? $job->hold_amount_cents ?? 0);

/* ------------------------------- URLs -------------------------------- */
$backUrl = route('portal.pay.show.job', ['job' => $job->getKey()]);
@endphp

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Payment complete • Job #{{ $job->id }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://js.stripe.com/v3/"></script>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%2216%22 font-size=%2218%22>✅</text></svg>">
  <style>
    .card-shadow{ box-shadow: 0 16px 40px rgba(2,6,23,.08), 0 6px 18px rgba(2,6,23,.06); }
    .mono{ font-variant-numeric: tabular-nums; }
    .badge{ padding:2px 10px; border-radius:9999px; font-size:12px; font-weight:600 }
  </style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="bg-white rounded-3xl card-shadow p-6 sm:p-8">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight">Payment complete</h1>
          <p class="text-slate-500">Job #{{ $job->id }} @if($job->title) • {{ $job->title }} @endif</p>
        </div>
        <div id="overall-status" class="{{ $remainingCents === 0 ? '' : 'hidden' }}">
          <span id="overall-badge" class="badge {{ $remainingCents === 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
            {{ $remainingCents === 0 ? 'Paid' : 'No details' }}
          </span>
        </div>
      </div>

      {{-- Summary grid --}}
      <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left: confirmation messages + ids --}}
        <section class="lg:col-span-2 space-y-4">
          <div id="messages" class="space-y-3">
            {{-- JS fills live status if client/setup secrets are present --}}
            @if(!$clientSecret && !$setupSecret)
              <div class="rounded-xl px-4 py-3 text-sm font-medium bg-amber-50 text-amber-800 border border-amber-200">
                No confirmation details found on this page. If you just paid, your receipt will arrive by email shortly.
              </div>
            @endif
          </div>

          <div id="ids-panel" class="hidden rounded-2xl border border-slate-200 p-4 bg-slate-50/60">
            <h3 class="font-semibold mb-2">Stripe references</h3>
            <dl class="text-sm text-slate-700 space-y-1 mono">
              <div class="flex items-center justify-between gap-4">
                <dt class="text-slate-500">PaymentIntent</dt>
                <dd><span id="pi-id" class="font-medium">—</span> <span id="pi-status" class="ml-2 text-slate-500"></span></dd>
              </div>
              <div class="flex items-center justify-between gap-4">
                <dt class="text-slate-500">SetupIntent</dt>
                <dd><span id="si-id" class="font-medium">—</span> <span id="si-status" class="ml-2 text-slate-500"></span></dd>
              </div>
            </dl>
          </div>

          <div class="rounded-2xl border border-slate-200 p-4">
            <h3 class="font-semibold mb-2">What happens next</h3>
            <ul class="list-disc pl-5 text-sm text-slate-700 space-y-1">
              <li>If your card required extra authentication, the payment may show as <strong>processing</strong> for a short time.</li>
              <li>We’ll email a receipt to the address on file (and to the one you entered during checkout, if provided).</li>
              @if($holdCents > 0)
                <li>A refundable security hold of <strong>{{ $formatMoney($holdCents, $currency) }}</strong> may appear as “pending” on your card. It’s not a charge and will be released automatically per our policy.</li>
              @endif
              <li>Need help? Reply to this email or contact support with Job #{{ $job->id }}.</li>
            </ul>
          </div>

          <div class="flex flex-wrap gap-3 pt-2">
            <a href="{{ $backUrl }}" class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-4 py-3 hover:bg-slate-800">Back to payment page</a>
            @if(url()->previous())
            <a href="{{ url()->previous() }}" class="inline-flex items-center justify-center rounded-xl bg-slate-600 text-white px-4 py-3 hover:bg-slate-700">Back</a>
            @endif
          </div>
        </section>

        {{-- Right: booking details --}}
        <aside class="lg:col-span-1">
          <div class="rounded-2xl border border-slate-200 p-5 bg-white">
            <h3 class="font-semibold">Booking details</h3>
            <dl class="mt-3 space-y-3 text-sm">
              <div class="flex items-center justify-between">
                <dt class="text-slate-600">Customer</dt>
                <dd class="font-medium">
                  {{ $job->customer_name ?? optional($job->customer)->name ?? '—' }}
                  @if($job->customer_email || optional($job->customer)->email)
                    <div class="text-slate-500">{{ $job->customer_email ?? optional($job->customer)->email }}</div>
                  @endif
                </dd>
              </div>

              @if($start || $end)
              <div class="flex items-start justify-between">
                <dt class="text-slate-600 mt-1">Dates</dt>
                <dd class="text-right">
                  <div class="mono">
                    {{ $start ? $start->isoFormat('ddd D MMM YYYY, HH:mm') : 'TBC' }}
                    <span class="opacity-60">→</span>
                    {{ $end ? $end->isoFormat('ddd D MMM YYYY, HH:mm') : 'TBC' }}
                  </div>
                  <div class="text-slate-500 text-xs">
                    {{ $duration ? "($duration)" : '' }} {{ $tzShort }}
                  </div>
                </dd>
              </div>
              @endif

              @if(isset($job->vehicle) || ($job->vehicle_name ?? false))
              <div class="flex items-center justify-between">
                <dt class="text-slate-600">Vehicle</dt>
                <dd class="font-medium">
                  {{ $job->vehicle_name ?? optional($job->vehicle)->name ?? '—' }}
                  @if(optional($job->vehicle)->registration)
                    <div class="text-slate-500 text-xs">Reg: {{ $job->vehicle->registration }}</div>
                  @endif
                </dd>
              </div>
              @endif

              <div class="h-px bg-slate-200"></div>

              <div class="flex items-center justify-between">
                <dt class="text-slate-600">Total</dt>
                <dd class="font-medium mono">{{ $formatMoney($totalCents, $currency) }}</dd>
              </div>
              <div class="flex items-center justify-between">
                <dt class="text-slate-600">Paid so far</dt>
                <dd class="font-medium mono">{{ $formatMoney($paidCents, $currency) }}</dd>
              </div>
              <div class="flex items-center justify-between">
                <dt class="text-slate-700 font-medium">Balance</dt>
                <dd class="text-lg font-semibold mono">{{ $formatMoney($remainingCents, $currency) }}</dd>
              </div>
              @if($holdCents > 0)
              <div class="flex items-center justify-between text-xs">
                <dt class="text-slate-500">Security hold (auth)</dt>
                <dd class="font-medium mono">{{ $formatMoney($holdCents, $currency) }}</dd>
              </div>
              @endif
            </dl>
          </div>
        </aside>
      </div>
    </div>
  </div>

<script>
(() => {
  const pk = @json($pk);
  const messages = document.getElementById('messages');
  const idsPanel = document.getElementById('ids-panel');
  const piIdEl   = document.getElementById('pi-id');
  const piStEl   = document.getElementById('pi-status');
  const siIdEl   = document.getElementById('si-id');
  const siStEl   = document.getElementById('si-status');
  const overall  = document.getElementById('overall-status');
  const obadge   = document.getElementById('overall-badge');

  const add = (cls, text) => {
    const base = 'rounded-xl px-4 py-3 text-sm font-medium';
    const styles = {
      ok:   'bg-emerald-50 text-emerald-700 border border-emerald-200',
      warn: 'bg-amber-50 text-amber-800 border border-amber-200',
      err:  'bg-rose-50 text-rose-700 border border-rose-200'
    }[cls] || 'bg-slate-50 text-slate-700 border border-slate-200';
    const div = document.createElement('div');
    div.className = base + ' ' + styles;
    div.textContent = text;
    messages.appendChild(div);
  };

  const setBadge = (variant, text) => {
    const classes = {
      success: 'badge bg-emerald-100 text-emerald-700',
      processing: 'badge bg-amber-100 text-amber-800',
      failed: 'badge bg-rose-100 text-rose-700',
      unknown: 'badge bg-slate-200 text-slate-700',
    }[variant] || 'badge bg-slate-200 text-slate-700';
    obadge.className = classes;
    obadge.textContent = text;
    overall.classList.remove('hidden');
  };

  const qs = new URLSearchParams(window.location.search);
  const piSecret = @json($clientSecret) || qs.get('payment_intent_client_secret');
  const siSecret = @json($setupSecret)  || qs.get('setup_intent_client_secret');

  if (!pk) {
    add('warn', 'Payment processed, but publishable key is missing so we cannot display live status here.');
    if (!piSecret && !siSecret) add('warn', 'No confirmation details found on this page.');
    return;
  }

  const stripe = Stripe(pk);

  (async () => {
    let sawAny = false;
    try {
      if (piSecret) {
        const {paymentIntent} = await stripe.retrievePaymentIntent(piSecret);
        sawAny = true;
        idsPanel.classList.remove('hidden');
        piIdEl.textContent = paymentIntent?.id || '—';
        piStEl.textContent = paymentIntent ? `(${paymentIntent.status})` : '';

        switch (paymentIntent?.status) {
          case 'succeeded':
            add('ok', 'Final payment succeeded ✅');
            setBadge('success', 'Payment succeeded');
            break;
          case 'processing':
            add('warn', 'Your payment is processing. We’ll email you once it’s confirmed.');
            setBadge('processing', 'Payment processing');
            break;
          case 'requires_action':
            add('warn', 'Additional authentication may be required to complete your payment.');
            setBadge('processing', 'Action required');
            break;
          default:
            add('err', 'We could not confirm the final payment. Please contact support.');
            setBadge('failed', 'Payment not confirmed');
        }
      }

      if (siSecret) {
        const {setupIntent} = await stripe.retrieveSetupIntent(siSecret);
        sawAny = true;
        idsPanel.classList.remove('hidden');
        siIdEl.textContent = setupIntent?.id || '—';
        siStEl.textContent = setupIntent ? `(${setupIntent.status})` : '';
        if (setupIntent?.status === 'succeeded' || setupIntent?.status === 'processing') {
          add('ok', 'Your card has been saved for permitted off-session charges.');
        } else {
          add('warn', 'We could not save your card; you can try again from the payment page.');
        }
      }

      if (!sawAny) {
        add('warn', 'No confirmation details found on this page. If you just paid, your receipt will arrive by email shortly.');
        setBadge('unknown', 'No details');
      }
    } catch (e) {
      add('err', e.message || 'Unable to retrieve payment status.');
      setBadge('unknown', 'Status unavailable');
    }
  })();
})();
</script>
</body>
</html>
