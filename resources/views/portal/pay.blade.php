@php
    /** @var \App\Models\Job $job */
    use Illuminate\Support\Carbon;

    $toCents = function ($v): int {
        if (is_null($v)) return 0;
        if (is_int($v)) return $v;
        if (is_numeric($v) && str_contains((string) $v, '.')) {
            return (int) round(((float) $v) * 100);
        }
        return (int) $v;
    };

    // ----- Dates & times (with timezone) -----
    $tz = $job->timezone ?? config('app.timezone', 'UTC');

    // Parse if present, otherwise keep nulls
    $startAt = $job->start_at ? Carbon::parse($job->start_at)->timezone($tz) : null;
    $endAt   = $job->end_at   ? Carbon::parse($job->end_at)->timezone($tz)   : null;

    // Nicely formatted label, e.g. "Tue 9 Sep, 10:00 → Thu 11 Sep, 14:00 (2d 4h) • NZST"
    $fmt = function (Carbon $c) { return $c->isoFormat('ddd D MMM, HH:mm'); };
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

    // Currency priority: Job -> Flow -> NZD
    $currency = $job->currency
        ?? ($job->flow->currency ?? null)
        ?? 'NZD';

    $formatMoney = function (int $cents, string $cur = 'NZD'): string {
        $amount = number_format($cents / 100, 2);
        $symbol = match (strtoupper($cur)) {
            'NZD' => 'NZ$',
            'AUD' => 'A$',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => ''
        };
        return $symbol ? $symbol . $amount : ($amount . ' ' . strtoupper($cur));
    };

    // Totals
    $totalCents = $toCents($job->charge_amount ?? 0);

    // Paid so far (prefer attribute, else sum payments)
    $paidCents = $toCents($job->paid_amount_cents ?? 0);
    if ($paidCents === 0 && method_exists($job, 'payments')) {
        try {
            $paidCents = (int) $job->payments()
                ->whereIn('status', ['succeeded', 'captured'])
                ->sum('amount_cents');
        } catch (\Throwable $e) { /* ignore */ }
    }

    $remainingCts = max(0, $totalCents - $paidCents);

    // 🔒 Hold comes from FLOW only (column: hold_amount_cents)
    $holdCents = (int) ($job->flow->hold_amount_cents ?? 0);

    $stripeKey = config('services.stripe.key');
@endphp

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Secure Payment • {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    {{-- Tailwind for styling --}}
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- Stripe.js --}}
    <script src="https://js.stripe.com/v3/"></script>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%2216%22 font-size=%2218%22>🔒</text></svg>">
    <style>
        .card-shadow { box-shadow: 0 10px 24px rgba(2,6,23,0.08), 0 6px 12px rgba(2,6,23,0.06); }
        .sr-input, .StripeElement { padding: 12px; border-radius: 12px; border: 1px solid rgb(229,231,235); background: white; }
        .StripeElement--focus { outline: 2px solid rgb(59,130,246); outline-offset: 2px; }
        .badge { border-radius: 9999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
        .badge-green { background: #DCFCE7; color: #166534; }
        .badge-amber { background: #FEF3C7; color: #92400E; }
        .badge-slate { background: #E2E8F0; color: #0F172A; }
        .mono { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
<div class="min-h-screen">
    {{-- Top bar --}}
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

            {{-- Rental dates & times --}}
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
            {{-- Left: Payment form --}}
            <section class="lg:col-span-2">
                <div class="bg-white rounded-2xl card-shadow p-6">
                    {{-- Payment Request Button (Apple/Google Pay) --}}
                    <div id="payment-request-area" class="mb-6 hidden">
                        <div id="payment-request-button" class="w-full"></div>
                        <div class="mt-2 text-xs text-slate-500">Express checkout via Apple Pay / Google Pay (if supported).</div>
                        <div class="my-6 flex items-center gap-3">
                            <div class="h-px flex-1 bg-slate-200"></div>
                            <div class="text-xs uppercase tracking-wider text-slate-400">or pay with card</div>
                            <div class="h-px flex-1 bg-slate-200"></div>
                        </div>
                    </div>

                    {{-- Card entry --}}
                    <div class="space-y-4">
                        <div>
                            <label for="card-element" class="block text-sm font-medium mb-1">Card details</label>
                            <div id="card-element" class="StripeElement"></div>
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

                        {{-- ONE clear CTA: Charge + Hold in one step --}}
                        <div class="space-y-3">
                            <button id="bundle-btn"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white font-medium py-3 hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed">
                                @if($remainingCts > 0 && $holdCents > 0)
                                    Pay {{ $formatMoney($remainingCts, $currency) }} + Place hold {{ $formatMoney($holdCents, $currency) }}
                                @elseif($remainingCts > 0)
                                    Pay {{ $formatMoney($remainingCts, $currency) }}
                                @elseif($holdCents > 0)
                                    Place refundable hold {{ $formatMoney($holdCents, $currency) }}
                                @else
                                    Nothing due
                                @endif
                            </button>

                            @if($holdCents > 0)
                                <p class="text-xs text-slate-500">
                                    The hold is a <strong>temporary authorization</strong>, not a charge. It auto-releases after your hire unless capture is needed per the agreement (e.g., fuel or damage).
                                </p>
                            @endif

                            {{-- Copy link (for manual sharing) --}}
                            <button id="copy-link-btn"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-100 text-slate-900 font-medium py-3 hover:bg-slate-200 focus:ring-2 focus:ring-slate-400">
                                Copy secure payment link
                            </button>

                            <div id="action-message" class="text-sm"></div>
                        </div>

                        {{-- Error box --}}
                        <div id="error-box" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-700 p-3 text-sm"></div>
                    </div>
                </div>

                {{-- Trust & small print --}}
                <div class="mt-6 grid sm:grid-cols-3 gap-4">
                    <div class="rounded-xl bg-white card-shadow p-4">
                        <div class="font-medium">Security</div>
                        <p class="text-sm text-slate-600 mt-1">Stripe handles your card—{{ config('app.name') }} never sees full card numbers.</p>
                    </div>
                    <div class="rounded-xl bg-white card-shadow p-4">
                        <div class="font-medium">Receipts</div>
                        <p class="text-sm text-slate-600 mt-1">You’ll get an instant email receipt for every payment and hold release.</p>
                    </div>
                    <div class="rounded-xl bg-white card-shadow p-4">
                        <div class="font-medium">Support</div>
                        <p class="text-sm text-slate-600 mt-1">Need help? Email <a class="underline" href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.</p>
                    </div>
                </div>
            </section>

            {{-- Right: Summary --}}
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

                    {{-- Rental mini-section --}}
                    @if($startAt || $endAt)
                        <div class="mt-4 rounded-xl border border-slate-200 p-4 bg-slate-50 text-sm">
                            <div class="font-medium mb-1">Rental</div>
                            <dl class="space-y-2">
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Start</dt>
                                    <dd class="mono">{{ $startAt ? $fmt($startAt) : 'TBC' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">End</dt>
                                    <dd class="mono">{{ $endAt ? $fmt($endAt) : 'TBC' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Timezone</dt>
                                    <dd class="mono">{{ $tzShort }}</dd>
                                </div>
                                @if($durationLabel)
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Duration</dt>
                                    <dd class="mono">{{ $durationLabel }}</dd>
                                </div>
                                @endif
                            </dl>
                        </div>
                    @endif

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
                            <dt class="text-slate-700 font-medium">Final payment due</dt>
                            <dd class="text-lg font-semibold">{{ $formatMoney($remainingCts, $currency) }}</dd>
                        </div>

                        @if($holdCents > 0)
                            <div class="h-px bg-slate-200 my-2"></div>
                            <div class="flex items-center justify-between text-sm">
                                <dt class="text-slate-600">Security hold (auth)</dt>
                                <dd class="font-medium">{{ $formatMoney($holdCents, $currency) }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="mt-6 rounded-xl border border-slate-200 p-4 bg-slate-50 text-sm">
                        <div class="font-medium mb-1">Off-session authorization</div>
                        <p class="text-slate-600">
                            We may securely charge your card later (e.g., balance, extensions, fuel/tolls, damage) using Stripe tokens.
                        </p>
                    </div>

                    <div class="mt-6 flex items-center gap-2 text-slate-500 text-xs">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a6 6 0 00-6 6v2H5a3 3 0 00-3 3v6a3 3 0 003 3h14a3 3 0 003-3v-6a3 3 0 00-3-3h-1V8a6 6 0 00-6-6zm-4 8V8a4 4 0 118 0v2H8z"/></svg>
                        <span>256-bit TLS encryption • PCI DSS compliant • 3D Secure where supported</span>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <footer class="mt-10 py-8">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 text-xs text-slate-500">
            By paying you agree to the rental terms and fair-use policy. Refunds follow our standard policy unless required by law.
        </div>
    </footer>
</div>

{{-- Bundle Stripe logic: one click confirms charge + hold (if configured) --}}
<script>
(() => {
    const stripeKey = @json($stripeKey);
    if (!stripeKey) console.warn('Stripe publishable key missing (services.stripe.key).');
    const stripe   = stripeKey ? Stripe(stripeKey) : null;
    const elements = stripe ? stripe.elements({ appearance: { theme: 'stripe' } }) : null;

    const remainingCts = @json($remainingCts);
    const holdCts      = @json($holdCents);
    const currency     = @json($currency);

    const bundleBtn = document.getElementById('bundle-btn');
    const copyBtn   = document.getElementById('copy-link-btn');
    const errorBox  = document.getElementById('error-box');
    const actionMsg = document.getElementById('action-message');

    const cardElement = elements ? elements.create('card', { hidePostalCode: false }) : null;
    if (cardElement) cardElement.mount('#card-element');

    const setBusy = (el, busy) => {
        if (!el) return;
        el.disabled = !!busy;
        if (busy) {
            el.dataset._label = el.innerHTML;
            el.innerHTML = 'Processing…';
        } else if (el.dataset._label) {
            el.innerHTML = el.dataset._label;
        }
    };

    const showError = (msg) => {
        errorBox.textContent = msg || 'Something went wrong. Please try again.';
        errorBox.classList.remove('hidden');
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    const clearError = () => errorBox.classList.add('hidden');
    const toast = (html, cls='text-green-700') => {
        actionMsg.className = `text-center ${cls}`;
        actionMsg.innerHTML = html;
    };

    // Payment Request (Apple/Google Pay) – disabled for bundled flow to avoid partials
    const prArea = document.getElementById('payment-request-area');
    if (stripe && remainingCts > 0) {
        const paymentRequest = stripe.paymentRequest({
            country: 'NZ', currency: currency.toLowerCase(),
            total: { label: '{{ config('app.name') }}', amount: remainingCts },
            requestPayerName: true, requestPayerEmail: true
        });
        const prEl = elements.create('paymentRequestButton', { paymentRequest });
        paymentRequest.canMakePayment().then(r => { if (r) { prArea.classList.remove('hidden'); prEl.mount('#payment-request-button'); } });
        paymentRequest.on('paymentmethod', (ev) => {
            ev.complete('fail');
            showError('Express checkout supports balance only. Use the card form for charge + hold.');
        });
    }

    async function createBundle() {
        const url = @json(route('portal.pay.bundle', ['type' => 'job', 'id' => $job->id]));
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) }
        });
        if (!res.ok) {
            let m = 'Unable to start payment.';
            try { const j = await res.json(); if (j?.message) m = j.message; } catch(_) {}
            throw new Error(m);
        }
        return res.json();
    }

    async function confirmWithCard(clientSecret) {
        const { error, paymentIntent } = await stripe.confirmCardPayment(clientSecret, {
            payment_method: { card: cardElement }
        });
        if (error) throw error;
        return paymentIntent;
    }

    if (bundleBtn) {
        bundleBtn.addEventListener('click', async () => {
            clearError();
            if (!stripe || !cardElement) { showError('Payments are not available right now.'); return; }
            if (remainingCts <= 0 && holdCts <= 0) { showError('Nothing to pay or hold.'); return; }

            setBusy(bundleBtn, true);
            try {
                const bundle = await createBundle();

                if (bundle.charge_client_secret) {
                    const chargePI = await confirmWithCard(bundle.charge_client_secret);
                    if (chargePI?.status !== 'succeeded' && chargePI?.status !== 'processing') {
                        throw new Error('Charge did not complete: ' + (chargePI?.status || 'unknown'));
                    }
                }

                if (bundle.hold_client_secret) {
                    const holdPI = await confirmWithCard(bundle.hold_client_secret);
                    if (holdPI?.status !== 'requires_capture' && holdPI?.status !== 'processing') {
                        throw new Error('Hold did not authorize: ' + (holdPI?.status || 'unknown'));
                    }
                }

                const parts = [];
                if (remainingCts > 0) parts.push('Payment successful');
                if (holdCts > 0) parts.push('Hold placed');
                toast(parts.join(' + ') + ' ✅', 'text-green-700');
            } catch (e) {
                showError(e.message || String(e));
            } finally {
                setBusy(bundleBtn, false);
            }
        });
    }

    if (copyBtn) {
        copyBtn.addEventListener('click', async () => {
            try {
                const urlEndpoint = @json(route('portal.pay.url', ['job' => $job->id]));
                let shareUrl = window.location.href;
                try {
                    const res = await fetch(urlEndpoint, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) {
                        const j = await res.json();
                        if (j?.url) shareUrl = j.url;
                    }
                } catch(_) {}
                await navigator.clipboard.writeText(shareUrl);
                toast('Secure link copied to clipboard. 🔗', 'text-green-700');
            } catch {
                toast('Could not copy link. You can copy from the address bar.', 'text-slate-700');
            }
        });
    }
})();
</script>
</body>
</html>
