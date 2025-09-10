@php
/** @var \App\Models\Job $job */
use Illuminate\Support\Carbon;

$toCents = function ($v): int {
    if (is_null($v)) return 0;
    if (is_int($v)) return $v;
    if (is_numeric($v) && str_contains((string) $v, '.')) return (int) round(((float) $v) * 100);
    return (int) $v;
};

$tz = $job->timezone ?? config('app.timezone', 'UTC');
$startAt = $job->start_at ? Carbon::parse($job->start_at)->timezone($tz) : null;
$endAt   = $job->end_at   ? Carbon::parse($job->end_at)->timezone($tz)   : null;

$fmt = fn (Carbon $c) => $c->isoFormat('ddd D MMM, HH:mm');
$durationLabel = null;
if ($startAt && $endAt && $endAt->greaterThan($startAt)) {
    $totalMinutes = $endAt->diffInMinutes($startAt);
    $days = intdiv($totalMinutes, 60*24);
    $hours = intdiv($totalMinutes % (60*24), 60);
    $mins = $totalMinutes % 60;
    $parts = [];
    if ($days)  $parts[] = $days . 'd';
    if ($hours) $parts[] = $hours . 'h';
    if (!$days && !$hours && $mins) $parts[] = $mins . 'm';
    $durationLabel = implode(' ', $parts);
}
$tzShort = $startAt?->format('T') ?? $endAt?->format('T') ?? strtoupper($tz);

$currency      = $job->currency ?? optional($job->flow)->currency ?? 'NZD';
$currencyLower = strtolower($currency);

$formatMoney = function (int $cents, string $cur = 'NZD'): string {
    $amount = number_format($cents / 100, 2);
    $symbol = match (strtoupper($cur)) {
        'NZD' => 'NZ$', 'AUD' => 'A$', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', default => ''
    };
    return $symbol ? $symbol . $amount : ($amount . ' ' . strtoupper($cur));
};

$totalCents = $toCents($job->charge_amount ?? 0);
$paidCents  = $toCents($job->paid_amount_cents ?? 0);
if ($paidCents === 0 && method_exists($job, 'payments')) {
    try {
        $paidCents = (int) $job->payments()
            ->whereIn('status', ['succeeded', 'captured'])
            ->sum('amount_cents');
    } catch (\Throwable $e) {}
}

$remainingCts = max(0, $totalCents - $paidCents);
$holdCents    = (int) (optional($job->flow)->hold_amount_cents ?? 0);

$stripeKey = config('services.stripe.key');

/** Prefer URLs injected by controller (job-specific). Fallbacks are kept just in case. */
$bundleUrl = $bundleUrl ?? route('portal.pay.bundle.job', ['job' => $job->getKey()]);
$intentUrl = $intentUrl ?? route('portal.pay.intent.job',  ['job' => $job->getKey()]);
$successUrl = route('portal.pay.job.complete', ['job' => $job->getKey()]);
$recordUrl  = route('portal.pay.recordPaid',   ['job' => $job->getKey()]);  
@endphp

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Secure Payment • {{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://js.stripe.com/v3/"></script>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%2216%22 font-size=%2218%22>🔒</text></svg>">
<style>
  .card-shadow { box-shadow: 0 10px 24px rgba(2,6,23,0.08), 0 6px 12px rgba(2,6,23,0.06); }
  .badge { border-radius: 9999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
  .badge-green { background: #DCFCE7; color: #166534; }
  .badge-amber { background: #FEF3C7; color: #92400E; }
  .badge-slate { background: #E2E8F0; color: #0F172A; }
  .mono { font-variant-numeric: tabular-nums; }
  .sr-input { padding: 10px; border-radius: 10px; border: 1px solid rgb(229,231,235); background: white; }
  #payment-element { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
</style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
<div class="min-h-screen">
  <header class="border-b border-slate-200 bg-white/70 backdrop-blur supports-[backdrop-filter]:bg-white/60">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="h-9 w-9 rounded-2xl bg-slate-900 text-white grid place-items-center">🔒</div>
        <div>
          <div class="text-sm text-slate-500">Secured by</div>
          <div class="font-semibold tracking-tight">Stripe & {{ config('app.name') }}</div>
        </div>
      </div>
      <div class="hidden sm:flex items-center gap-2 text-sm">
        <span class="badge badge-slate">PCI DSS</span>
        <span class="badge badge-green">TLS 1.2+</span>
        <span class="badge badge-slate">3D Secure</span>
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-2">
      <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight">Complete your payment</h1>
      <p class="mt-1 text-slate-600">Job #{{ $job->id }} • {{ $job->title ?? 'Booking' }}</p>

      @if($startAt || $endAt)
        <div class="mt-2 text-sm text-slate-700 flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="font-medium">Rental:</span>
          <span class="mono">
            @if($startAt) {{ $fmt($startAt) }} @else <em>TBC</em> @endif
            <span class="opacity-60">→</span>
            @if($endAt) {{ $fmt($endAt) }} @else <em>TBC</em> @endif
          </span>
          <span class="text-slate-500">
            @if($durationLabel) ({{ $durationLabel }}) @endif • {{ $tzShort }}
          </span>
        </div>
      @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start mt-4">
      <!-- LEFT: Payment Element + details -->
      <section class="lg:col-span-2">
        <div class="bg-white rounded-2xl card-shadow p-6">
          <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 p-4 bg-slate-50/60">
              <h2 class="font-semibold mb-2">What you’ll authorize now</h2>
              <ul class="text-sm text-slate-700 list-disc pl-5 space-y-1">
                <li><strong>Final payment:</strong> {{ $formatMoney($remainingCts, $currency) }} charged today.</li>
                @if($holdCents > 0)
                <li><strong>Refundable security hold:</strong> {{ $formatMoney($holdCents, $currency) }} authorized (not charged) to cover bond or incidentals.</li>
                @endif
                <li><strong>Save card for off-session:</strong> your card is securely saved to settle any permitted adjustments later (we’ll notify you before any charge).</li>
              </ul>
            </div>

            <div>
              <label class="block text-sm font-medium mb-1">Card payment</label>
              <div id="payment-element"></div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium mb-1" for="payer-name">Name on card</label>
                <input id="payer-name" class="sr-input w-full" placeholder="Jane Appleseed" autocomplete="cc-name">
              </div>
              <div>
                <label class="block text-sm font-medium mb-1" for="payer-email">Receipt email</label>
                <input id="payer-email" class="sr-input w-full" placeholder="you@example.com" type="email" autocomplete="email" value="{{ old('email', $job->customer->email ?? '') }}">
              </div>
            </div>

            <button id="pay-btn"
              class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white font-medium py-3 hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed">
              @if($holdCents > 0)
                Pay {{ $formatMoney($remainingCts, $currency) }} & authorize hold {{ $formatMoney($holdCents, $currency) }}
              @else
                Pay {{ $formatMoney($remainingCts, $currency) }}
              @endif
            </button>

            <div id="error-box" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-700 p-3 text-sm"></div>
            <div id="action-message" class="text-sm"></div>
          </div>
        </div>
      </section>

      <!-- RIGHT: Summary -->
      <aside class="lg:col-span-1">
        <div class="bg-white rounded-2xl card-shadow p-6 sticky top-6">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Payment summary</h2>
            @if($remainingCts === 0)
              <span class="badge badge-green">Paid</span>
            @else
              <span class="badge badge-amber">Unpaid</span>
            @endif
          </div>

          <dl class="mt-4 space-y-3">
            <div class="flex items-center justify-between text-sm">
              <dt class="text-slate-600">Total</dt>
              <dd class="font-medium">{{ $formatMoney($totalCents, $currency) }}</dd>
            </div>
            <div class="flex items-center justify-between text-sm">
              <dt class="text-slate-600">Paid so far</dt>
              <dd class="font-medium">{{ $formatMoney($paidCents, $currency) }}</dd>
            </div>
            <div class="h-px bg-slate-200 my-2"></div>
            <div class="flex items-center justify-between">
              <dt class="text-slate-700 font-medium">Amount due now</dt>
              <dd class="text-lg font-semibold">{{ $formatMoney($remainingCts, $currency) }}</dd>
            </div>
            @if($holdCents > 0)
              <div class="h-px bg-slate-200 my-2"></div>
              <div class="text-xs text-slate-600">
                A refundable security hold of <strong>{{ $formatMoney($holdCents, $currency) }}</strong> will be authorized now (not charged).
              </div>
            @endif
          </dl>
        </div>
      </aside>
    </div>
  </main>
</div>

<script>
(() => {
  const stripeKey = @json($stripeKey);
  if (!stripeKey) { console.warn('Stripe publishable key missing.'); return; }

  const successUrl = @json($successUrl);
  const bundleUrl  = @json($bundleUrl);
  const recordUrl  = @json($recordUrl);
  const csrfToken  = @json(csrf_token());

  const payerNameEl = document.getElementById('payer-name');
  const payerMailEl = document.getElementById('payer-email');
  const payBtn      = document.getElementById('pay-btn');
  const errorBox    = document.getElementById('error-box');
  const actionMsg   = document.getElementById('action-message');

  const stripe = Stripe(stripeKey);
  let elements, secrets = {};

  const appearance = {
    theme: 'flat',
    variables: {
      colorPrimary: '#4f46e5',
      colorBackground: '#ffffff',
      colorText: '#0f172a',
      colorDanger: '#e11d48',
      borderRadius: '10px',
      fontSizeBase: '14px',
      spacingUnit: '6px',
    },
    rules: {
      '.Input': { border: '1px solid #e5e7eb', padding: '8px' },
      '.Input:focus': { border: '1px solid #4f46e5', boxShadow: '0 0 0 3px rgba(79,70,229,.15)' },
      '.Tab, .Block': { borderRadius: '10px' },
      '.Label': { fontWeight: '600', color: '#334155' },
    },
  };

  const setBusy = (busy, label = 'Processing…') => {
    if (!payBtn) return;
    payBtn.disabled = !!busy;
    if (busy) { payBtn.dataset._label = payBtn.innerHTML; payBtn.innerHTML = label; }
    else if (payBtn.dataset._label) { payBtn.innerHTML = payBtn.dataset._label; }
  };
  const showError = (msg) => {
    errorBox.textContent = msg || 'Something went wrong. Please try again.';
    errorBox.classList.remove('hidden');
    errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  const clearError = () => errorBox.classList.add('hidden');

  async function postJSON(url, body) {
    const res  = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify(body || {})
    });
    const text = await res.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch { json = { ok:false, message:text || res.statusText }; }
    if (!res.ok || json.ok === false) throw new Error(json.message || `HTTP ${res.status}`);
    return json;
  }

  async function recordPaid(id) {
    try { await postJSON(recordUrl, { payment_intent: id }); } catch {}
  }

  async function mountElements(clientSecret) {
    if (elements) { elements.update({ clientSecret }); return; }
    elements = stripe.elements({ clientSecret, appearance });
    elements.create('payment').mount('#payment-element');
  }

  async function ensureElements(secret) {
    if (!secret) return;
    if (!elements) await mountElements(secret);
    else elements.update({ clientSecret: secret });
  }

  async function confirmCharge() {
    if (!secrets.charge_client_secret) return;
    await ensureElements(secrets.charge_client_secret);
    const { error: submitError } = await elements.submit(); // ✅ submit with this secret
    if (submitError) throw submitError;
    const { error, paymentIntent } = await stripe.confirmPayment({
      elements,
      clientSecret: secrets.charge_client_secret,
      redirect: 'if_required',
      confirmParams: {
        payment_method_data: {
          billing_details: {
            name:  payerNameEl?.value || undefined,
            email: payerMailEl?.value || undefined,
          }
        }
      }
    });
    if (error) throw error;
    if (paymentIntent?.status === 'succeeded') {
      await recordPaid(paymentIntent.id);
    } else if (paymentIntent?.status !== 'processing') {
      throw new Error('Charge did not complete (' + (paymentIntent?.status || 'unknown') + ').');
    }
  }

  async function confirmHold() {
    if (!secrets.hold_client_secret) return;
    await ensureElements(secrets.hold_client_secret);
    const { error: submitError } = await elements.submit(); // ✅ submit with hold secret
    if (submitError) throw submitError;
    const { error, paymentIntent } = await stripe.confirmPayment({
      elements,
      clientSecret: secrets.hold_client_secret,
      redirect: 'if_required',
      confirmParams: {
        payment_method_data: {
          billing_details: {
            name:  payerNameEl?.value || undefined,
            email: payerMailEl?.value || undefined,
          }
        }
      }
    });
    if (error) throw error;
    const st = paymentIntent?.status;
    if (st !== 'requires_capture' && st !== 'processing') {
      throw new Error('Hold not authorized (' + (st || 'unknown') + ').');
    }
  }

  async function confirmSetup() {
    if (!secrets.setup_client_secret) return;
    await ensureElements(secrets.setup_client_secret);
    const { error: submitError } = await elements.submit(); // ✅ submit with setup secret
    if (submitError) throw submitError;
    const { error, setupIntent } = await stripe.confirmSetup({
      elements,
      clientSecret: secrets.setup_client_secret,
      redirect: 'if_required',
      confirmParams: {
        payment_method_data: {
          billing_details: {
            name:  payerNameEl?.value || undefined,
            email: payerMailEl?.value || undefined,
          }
        }
      }
    });
    if (error) throw error;
    if (setupIntent?.status !== 'succeeded' && setupIntent?.status !== 'processing') {
      throw new Error('Card save not completed (' + (setupIntent?.status || 'unknown') + ').');
    }
  }

  // Prepare bundle + mount UI
  (async function init() {
    try {
      setBusy(true, 'Preparing…');
      const receipt = (payerMailEl?.value || '').trim();
      secrets = await postJSON(bundleUrl, {
        requested_charge_cents: {{ (int) $remainingCts }},
        requested_hold_cents:   {{ (int) $holdCents }},
        receipt_email: receipt || undefined,
      });
      const first = secrets.charge_client_secret || secrets.hold_client_secret || secrets.setup_client_secret;
      if (!first) throw new Error('Unable to initialize payment UI.');
      await mountElements(first);
    } catch (e) {
      showError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  })();

  // Click handler
  payBtn?.addEventListener('click', async () => {
    clearError();
    setBusy(true);
    try {
      await confirmCharge();
      await confirmHold();
      await confirmSetup();

      actionMsg.className = 'text-green-700 text-center';
      actionMsg.textContent = 'Payment captured, hold authorized, and card saved for off-session ✅';
      window.location.href = successUrl;
    } catch (e) {
      showError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  });
})();
</script>

</body>
</html>
