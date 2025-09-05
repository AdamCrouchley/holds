{{-- resources/views/portal/pay.blade.php --}}
@php
    /**
     * Unified Pay Blade (2025)
     * Supports:
     *  - $booking (legacy Booking portal flow)
     *  - $job     (new Jobs/Holds flow)
     * Optional overrides from controller:
     *  - $amountCents (int) amount to charge (in cents)
     *  - $holdCents   (int) hold/bond to authorize (in cents)
     *  - $paidCents   (int) paid-to-date (in cents)
     *  - $remainingCents (int) remaining (in cents)
     */

    /** Stripe publishable key (safe to expose) */
    $stripePk = config('services.stripe.key') ?: env('STRIPE_KEY');

    /** Identify context */
    $isBooking = isset($booking) && $booking;
    $isJob     = isset($job) && $job;

    if (!$isBooking && !$isJob) {
        throw new \RuntimeException('portal/pay requires either $booking or $job.');
    }

    // ---- Currency / timezone -------------------------------------------------
    $tz  = $isBooking
        ? (($user->portal_timezone ?? null) ?: config('app.timezone', 'Pacific/Auckland'))
        : (config('app.timezone', 'Pacific/Auckland'));
    $cur = $isBooking
        ? ($booking->currency ?? 'NZD')
        : ($job->hold_currency ?? 'NZD');

    $fmtMoney = fn ($cents) => ($cur ?: 'NZD') . ' ' . number_format(((int)($cents ?? 0))/100, 2);

    // ---- References / dates --------------------------------------------------
    if ($isBooking) {
        $ref   = $booking->reference ?? ('BK'.str_pad((string)$booking->id, 6, '0', STR_PAD_LEFT));
        $start = optional($booking->start_at)->timezone($tz);
        $end   = optional($booking->end_at)->timezone($tz);
    } else {
        $ref   = $job->external_reference ?? ('JOB-'.str_pad((string)$job->id, 6, '0', STR_PAD_LEFT));
        $start = optional($job->start_at)->timezone($tz);
        $end   = optional($job->end_at)->timezone($tz);
    }

    // ---- Customer info -------------------------------------------------------
    if ($isBooking) {
        $cust      = $booking->customer ?? null;
        $custName  = trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: ($user->name ?? 'Guest');
        $custEmail = $cust->email ?? ($user->email ?? null);
    } else {
        $custName  = $job->customer_name ?? 'Guest';
        $custEmail = $job->customer_email ?? null;
    }

    // ---- Money: totals vs charge-only ---------------------------------------
    if ($isBooking) {
        $total   = (int) ($total   ?? (int)($booking->total_amount   ?? 0));
        $paid    = (int) ($paid    ?? (int)($booking->amount_paid    ?? 0));
        $balance = (int) ($balance ?? max(0, $total - $paid));
        $computedAmountToPayCents = $balance;
        $computedHoldCents        = (int) ($booking->hold_amount ?? 0);
    } else {
        // In Job flow we take what the Job says to charge and hold
        $computedAmountToPayCents = (int) ($job->charge_amount_cents ?? 0);
        $computedHoldCents        = (int) ($job->hold_amount_cents   ?? 0);

        // For a basic summary if not provided, assume nothing paid yet.
        $total   = (int) ($job->total_amount_cents ?? $computedAmountToPayCents);
        $paid    = (int) ($job->paid_amount_cents  ?? 0);
        $balance = (int) ($job->remaining_amount_cents ?? max(0, $total - $paid));
    }

    // Allow controller overrides for a simple integration path
    $amountToPayCents = isset($amountCents) ? (int)$amountCents : $computedAmountToPayCents;
    $holdAmt          = isset($holdCents)   ? (int)$holdCents   : $computedHoldCents;

    // Values for the new “Paid/Remaining/To Pay/Hold” block
    $uiPaidCents      = isset($paidCents) ? (int)$paidCents : $paid;
    $uiRemainingCents = isset($remainingCents) ? (int)$remainingCents : $balance;
    $uiToPayNowCents  = $uiRemainingCents ?? $amountToPayCents;

    // ---- Misc UI flags -------------------------------------------------------
    $claimEnabled          = $isBooking ? (bool)($booking->can_claim ?? false) : false;
    $tokenPayUrl           = $isBooking && method_exists($booking, 'getPortalUrlAttribute') ? $booking->portal_url : null;
    $enablePaymentRequest  = true;

    // ---- Routes for API calls (try Job first; fallback to Booking) -----------
    try {
        $intentRoute = $isJob
            ? route('portal.intent', ['job' => $job->id])
            : route('portal.intent', ['booking' => $booking->id]);
    } catch (\Throwable $e) {
        $intentRoute = $isJob
            ? url('p/intent/'.$job->id)
            : url('p/intent/'.$booking->id);
    }

    try {
        $holdRecordedRoute = $isJob
            ? route('portal.pay.hold-recorded', ['job' => $job->id])
            : route('portal.pay.hold-recorded', ['booking' => $booking->id]);
    } catch (\Throwable $e) {
        $holdRecordedRoute = $isJob
            ? url('p/pay/'.$job->id.'/hold-recorded')
            : url('p/pay/'.$booking->id.'/hold-recorded');
    }
@endphp

<!doctype html>
<html lang="en" class="h-full bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isBooking ? "Pay for Booking $ref" : "Pay for $ref" }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css'])
    <style>
        /* Lightweight extras so this looks classy even without Tailwind */
        :root { color-scheme: light dark; --fg:#111; --muted:#64748b; }
        .glass { background: rgba(255,255,255,.72); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .dark .glass { background: rgba(2,6,23,.5); }
        .elevate { box-shadow: 0 10px 30px -12px rgba(2,6,23,.25), 0 8px 16px -12px rgba(2,6,23,.18); }
        .ring-subtle { box-shadow: inset 0 0 0 1px rgba(15,23,42,.06); }
        .dark .ring-subtle { box-shadow: inset 0 0 0 1px rgba(148,163,184,.15); }
        .chip { display:inline-flex;align-items:center;gap:.5rem;padding:.375rem .7rem;border-radius:9999px; box-shadow: inset 0 0 0 1px rgba(15,23,42,.08); }
        .dark .chip { box-shadow: inset 0 0 0 1px rgba(148,163,184,.2); }
        .btn-primary:disabled { opacity:.6; cursor:not-allowed; }
        .smooth { transition: all .2s ease; }
        .hidden-important { display:none !important; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Inter, Arial, sans-serif; color: var(--fg); }
        input, .card-box { border-radius: 0.75rem; border: 1px solid rgba(100,116,139,.25); padding: .75rem; }
        .text-muted { color: var(--muted); }
        /* Minimal card grid for the inserted block */
        .inline-card { background: rgba(241,245,249,.8); border-radius: 14px; padding: 1rem 1.25rem; }
        .inline-grid { display: grid; gap: .5rem; grid-template-columns: 1fr 1fr; }
        .label { font-size: .85rem; color: var(--muted); }
        .value { font-weight: 600; }
    </style>
</head>
<body class="h-full font-sans text-slate-900 dark:text-slate-100 antialiased selection:bg-indigo-200/60 dark:selection:bg-indigo-500/30">
<div class="min-h-screen">

    {{-- Top / hero --}}
    <header class="border-b border-white/40 dark:border-slate-800/60">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 py-6 sm:py-8 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="h-11 w-11 rounded-2xl grid place-content-center bg-indigo-600 text-white shadow-md">
                    <span class="font-semibold">{{ (config('app.name')[0] ?? 'D') . (config('app.name')[1] ?? 'D') }}</span>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-muted">
                        {{ $isBooking ? 'Booking' : 'Job' }}
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="text-lg font-semibold">#{{ $ref }}</div>
                        <span class="chip text-xs bg-white/70 dark:bg-slate-900/40">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor"><path d="M9 12.75 11.25 15 15 9.75" /><path fill-rule="evenodd" d="M2.25 12a9.75 9.75 0 1 1 19.5 0 9.75 9.75 0 0 1 19.5 0Zm9.75-8.25a8.25 8.25 0 1 0 0 16.5 8.25 8.25 0 0 0 0-16.5Z" clip-rule="evenodd"/></svg>
                            Secure checkout
                        </span>
                    </div>
                    @if($custName || $custEmail)
                        <div class="text-xs text-muted mt-1">
                            {{ $custName }}@if($custEmail) &lt;{{ $custEmail }}&gt;@endif
                        </div>
                    @endif
                </div>
            </div>
            @if($tokenPayUrl)
                <a href="{{ $tokenPayUrl }}"
                   class="text-sm font-medium text-indigo-700 hover:text-indigo-900 underline underline-offset-4 dark:text-indigo-400 dark:hover:text-indigo-300 smooth">Back to portal</a>
            @endif
        </div>
    </header>

    {{-- Content --}}
    <main class="mx-auto max-w-5xl px-4 sm:px-6 py-6 sm:py-10 grid gap-6 lg:gap-8 md:grid-cols-5">

        {{-- Summary --}}
        <section class="md:col-span-2 space-y-4">
            <div class="glass elevate rounded-2xl p-5 md:p-6 ring-subtle">
                <h2 class="text-lg font-semibold mb-3">{{ $isBooking ? 'Booking Summary' : 'Summary' }}</h2>

                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-muted">Reference</dt>
                    <dd class="text-right font-medium">{{ $ref }}</dd>

                    @if($start)
                        <dt class="text-muted">Start</dt>
                        <dd class="text-right font-medium">{{ $start->format('D, M j, Y H:i') }}</dd>
                    @endif
                    @if($end)
                        <dt class="text-muted">End</dt>
                        <dd class="text-right font-medium">{{ $end->format('D, M j, Y H:i') }}</dd>
                    @endif

                    <dt class="text-muted">Total</dt>
                    <dd class="text-right font-medium">{{ $fmtMoney($total) }}</dd>

                    <dt class="text-muted">Paid</dt>
                    <dd class="text-right font-medium">{{ $fmtMoney($uiPaidCents) }}</dd>

                    <dt class="text-muted">{{ $isBooking ? 'Balance' : 'Amount due' }}</dt>
                    <dd class="text-right text-base font-semibold">{{ $fmtMoney($uiRemainingCents) }}</dd>
                </dl>

                @if($holdAmt > 0)
                    <div class="mt-4 rounded-xl border border-amber-200/70 bg-amber-50/80 dark:border-amber-500/30 dark:bg-amber-900/20 p-4">
                        <div class="text-sm text-amber-900 dark:text-amber-100">
                            <div class="font-medium">Security Bond (Card Hold)</div>
                            <p class="mt-1 leading-6">
                                A temporary hold of <span class="font-semibold">{{ $fmtMoney($holdAmt) }}</span>
                                will be placed on your card. It isn’t a charge and will be released automatically unless required.
                            </p>
                            <p class="mt-1 leading-6">
                                The hold is set to automatically cancel <span class="font-semibold">48 hours after</span> your {{ $isBooking ? 'booking' : 'rental' }} ends.
                            </p>
                        </div>
                    </div>
                @endif

                @if(!empty($payments ?? null) && count($payments))
                    <div class="mt-5">
                        <h3 class="text-sm font-semibold mb-2">Previous Payments</h3>
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-700/60 divide-y divide-slate-200/70 dark:divide-slate-700/60 bg-white/60 dark:bg-slate-900/40">
                            @foreach(($payments ?? []) as $p)
                                @php
                                    $amt = $fmtMoney((int)($p->amount ?? 0));
                                    $dt  = optional($p->created_at)->timezone($tz)?->format('M j, Y H:i');
                                @endphp
                                <div class="p-3 flex items-center justify-between text-sm">
                                    <span class="text-slate-600 dark:text-slate-300">{{ $dt ?? '—' }}</span>
                                    <span class="font-medium">{{ $amt }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <ul class="mt-4 list-disc list-inside text-xs text-muted space-y-1">
                    <li>Payments are processed securely by Stripe.</li>
                    <li>Your card details never touch our servers.</li>
                    <li>All amounts shown in {{ $cur }}.</li>
                </ul>
            </div>

            {{-- INSERTED: Compact “Paid/Remaining/To pay/Hold” card + Rental Term explainer --}}
            <div class="glass elevate rounded-2xl p-5 md:p-6 ring-subtle space-y-4">
                <h2 class="text-lg font-semibold">Payment</h2>
                <p class="text-sm text-muted">Reference: <strong>{{ $ref }}</strong></p>
                @if($custName || $custEmail)
                    <p class="text-sm text-muted">Customer:
                        <strong>{{ $custName }}</strong>
                        @if($custEmail) &lt;{{ $custEmail }}&gt; @endif
                    </p>
                @endif>

                <div class="inline-card">
                    <div class="inline-grid">
                        <div>
                            <div class="label">Paid to date</div>
                            <div class="value">{{ $fmtMoney($uiPaidCents) }}</div>
                        </div>
                        <div>
                            <div class="label">Remaining</div>
                            <div class="value">
                                {{ is_null($uiRemainingCents) ? 'TBC' : $fmtMoney(max(0, (int)$uiRemainingCents)) }}
                            </div>
                        </div>
                        <div>
                            <div class="label">Amount to pay now</div>
                            <div class="value">
                                {{ is_null($uiToPayNowCents) ? 'TBC' : $fmtMoney(max(0, (int)$uiToPayNowCents)) }}
                            </div>
                        </div>
                        <div>
                            <div class="label">Security hold (pre-auth)</div>
                            <div class="value">{{ $holdAmt ? $fmtMoney($holdAmt) : 'TBC' }}</div>
                        </div>
                    </div>
                </div>

                <h3 class="text-base font-semibold mt-2">Rental term</h3>
                <p class="text-sm text-muted mt-1">The dates below are the start and end of your vehicle rental.</p>
                <div class="inline-card">
                    <div class="inline-grid">
                        <div>
                            <div class="label">Rental start</div>
                            <div class="value">
                                {{ $start ? $start->format('D, d M Y, h:ia') : 'TBC' }}
                            </div>
                        </div>
                        <div>
                            <div class="label">Rental end</div>
                            <div class="value">
                                {{ $end ? $end->format('D, d M Y, h:ia') : 'TBC' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Payment --}}
        <section class="md:col-span-3">
            <div class="glass elevate rounded-2xl p-5 md:p-6 ring-subtle space-y-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Pay & Secure Card</h2>
                    <span class="chip text-xs bg-white/70 dark:bg-slate-900/40">
                        <svg class="h-4 w-4 text-indigo-600 dark:text-indigo-400" viewBox="0 0 24 24" fill="currentColor"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h13A2.5 2.5 0 0 1 21 7.5v9A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5v-9Z"/><path d="M3 9h18"/></svg>
                        Card & Wallet supported
                    </span>
                </div>

                <form id="pay-form" class="space-y-5" onsubmit="return false;">
                    @csrf
                    <input type="hidden" id="subject-id" value="{{ $isBooking ? $booking->id : $job->id }}">
                    <input type="hidden" id="amount-cents" value="{{ (int)($amountToPayCents ?? 0) }}">
                    <input type="hidden" id="hold-cents" value="{{ (int)($holdAmt ?? 0) }}">
                    <input type="hidden" id="intent-endpoint" value="{{ $intentRoute }}">
                    <input type="hidden" id="hold-recorded-endpoint" value="{{ $holdRecordedRoute }}">

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm text-slate-600 dark:text-slate-300">Name on card</span>
                            <input type="text" id="payer-name" value="{{ $custName }}"
                                   class="mt-1 w-full bg-white/80 dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 focus:border-indigo-500 focus:ring-indigo-500 smooth card-box"
                                   autocomplete="cc-name" required>
                        </label>
                        <label class="block">
                            <span class="text-sm text-slate-600 dark:text-slate-300">Email for receipt</span>
                            <input type="email" id="payer-email" value="{{ $custEmail }}"
                                   class="mt-1 w-full bg-white/80 dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 focus:border-indigo-500 focus:ring-indigo-500 smooth card-box"
                                   autocomplete="email" required>
                        </label>
                    </div>

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        You will be charged
                        <span class="font-semibold">{{ $fmtMoney($amountToPayCents) }}</span>.
                    </p>

                    {{-- Payment Request (Apple/Google Pay) --}}
                    <div id="payment-request-container" class="{{ $enablePaymentRequest ? '':'hidden-important' }}">
                        <div id="payment-request-button" class="mb-3"></div>
                        <div class="text-xs text-muted -mt-2 mb-2">Or pay with card below</div>
                    </div>

                    {{-- Card Element --}}
                    <div>
                        <span class="text-sm text-slate-600 dark:text-slate-300">Card details</span>
                        <div id="card-element" class="mt-2 p-3 card-box bg-white/80 dark:bg-slate-900/40"></div>
                        <div id="card-errors" role="alert" class="mt-2 text-sm text-rose-600"></div>
                    </div>

                    {{-- Consent / explainer --}}
                    <div class="rounded-xl bg-slate-50/80 dark:bg-slate-900/40 ring-subtle p-4 text-xs leading-6 text-slate-600 dark:text-slate-300">
                        <p>By paying, you agree that:</p>
                        <ul class="list-disc list-inside mt-1 space-y-1">
                            <li>We’ll place a temporary hold of <span class="font-semibold">{{ $fmtMoney($holdAmt) }}</span> on your card (if applicable).</li>
                            <li>Your card may be saved for post-hire charges as per our terms.</li>
                            <li>Holds automatically cancel 48 hours after the {{ $isBooking ? 'booking' : 'rental' }} ends unless otherwise required.</li>
                        </ul>
                    </div>

                    <div class="flex items-center justify-between">
                        @if($isBooking && $claimEnabled)
                            <a href="{{ route('portal.claim', ['booking' => $booking->id]) }}"
                               class="text-sm text-indigo-700 hover:text-indigo-900 underline underline-offset-4 dark:text-indigo-400 dark:hover:text-indigo-300 smooth">Submit a claim instead</a>
                        @else
                            <span></span>
                        @endif

                        <button id="pay-btn" type="submit"
                                class="btn-primary inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-white font-medium hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 dark:focus-visible:ring-offset-slate-900 disabled:opacity-50 smooth">
                            <svg id="pay-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <span id="pay-label">Pay {{ $fmtMoney($amountToPayCents) }}</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Trust footer block --}}
            <div class="mt-4 text-xs text-muted flex items-center gap-2">
                <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12.75 11.25 15 15 9.75" /><path fill-rule="evenodd" d="M2.25 12a9.75 9.75 0 1 1 19.5 0 9.75 9.75 0 0 1 19.5 0Zm9.75-8.25a8.25 8.25 0 1 0 0 16.5 8.25 8.25 0 0 0 0-16.5Z" clip-rule="evenodd"/></svg>
                Payments handled by Stripe. {{ config('app.name') }} never stores your card details.
            </div>
        </section>
    </main>

    <footer class="py-10 text-center text-xs text-muted">
        © {{ date('Y') }} {{ config('app.name') }}
    </footer>
</div>

{{-- Stripe --}}
<script src="https://js.stripe.com/v3/"></script>
<script>
(() => {
    const stripeKey = @json($stripePk);
    if (!stripeKey) {
        console.warn('Stripe publishable key missing. Set services.stripe.key or STRIPE_KEY.');
    }
    const stripe  = Stripe(stripeKey || 'pk_test_123'); // fallback prevents hard crash in dev
    const form    = document.getElementById('pay-form');
    const btn     = document.getElementById('pay-btn');
    const spinner = document.getElementById('pay-spinner');
    const label   = document.getElementById('pay-label');
    const nameEl  = document.getElementById('payer-name');
    const emailEl = document.getElementById('payer-email');

    const amountCents = parseInt(document.getElementById('amount-cents').value || '0', 10);
    const holdCents   = parseInt(document.getElementById('hold-cents').value || '0', 10);
    const intentUrl   = document.getElementById('intent-endpoint').value;
    const holdUrl     = document.getElementById('hold-recorded-endpoint').value;

    // Elements (Card)
    const elements = stripe.elements({
        appearance: { theme: document.documentElement.classList.contains('dark') ? 'night' : 'flat' }
    });
    const card = elements.create('card');
    card.mount('#card-element');
    card.on('change', (e) => {
        document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
    });

    // Payment Request (Apple/Google Pay) — fixed total only
    const pr = stripe.paymentRequest({
        country: 'NZ',
        currency: @json(strtolower($cur)),
        total: { label: 'Payment {{ $ref }}', amount: amountCents },
        requestPayerName: true,
        requestPayerEmail: true,
    });

    pr.canMakePayment().then(res => {
        if (res && {{ $enablePaymentRequest ? 'true' : 'false' }}) {
            const prButton = elements.create('paymentRequestButton', { paymentRequest: pr });
            document.getElementById('payment-request-container').classList.remove('hidden-important');
            prButton.mount('#payment-request-button');

            pr.on('paymentmethod', async (ev) => {
                try {
                    const bundle = await createBundle(amountCents, holdCents, emailEl.value, nameEl.value);

                    // 1) Confirm the charge PI (wallet)
                    const {error: payErr, paymentIntent: payPi} = await stripe.confirmCardPayment(bundle.pay_client_secret, {
                        payment_method: ev.paymentMethod.id
                    }, {handleActions: true});

                    if (payErr) { ev.complete('fail'); return showError(payErr.message || 'Payment failed.'); }

                    // 2) Confirm hold PI if needed (wallet)
                    if (holdCents > 0 && bundle.hold_client_secret) {
                        const {error: holdErr, paymentIntent: holdPi} = await stripe.confirmCardPayment(bundle.hold_client_secret, {
                            payment_method: ev.paymentMethod.id
                        }, {handleActions: true});

                        if (holdErr) { ev.complete('fail'); return showError(holdErr.message || 'Hold authorization failed.'); }

                        // notify server for bookkeeping/UI
                        await pingHoldRecorded(holdPi?.id);
                    }

                    ev.complete('success');
                    redirectSuccess();
                } catch (err) {
                    ev.complete('fail');
                    showError(err.message || 'Payment failed.');
                }
            });
        }
    });

    // Card submit
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setLoading(true);

        try {
            const bundle = await createBundle(amountCents, holdCents, emailEl.value, nameEl.value);

            // 1) Card payment for amount
            const { error: payError, paymentIntent: payPi } = await stripe.confirmCardPayment(bundle.pay_client_secret, {
                payment_method: {
                    card,
                    billing_details: { name: nameEl.value, email: emailEl.value }
                }
            });

            if (payError) throw payError;
            if (!payPi || (payPi.status !== 'succeeded' && payPi.status !== 'processing')) {
                throw new Error('Payment could not be completed.');
            }

            // 2) Authorize hold if required
            if (holdCents > 0 && bundle.hold_client_secret) {
                const { error: holdError, paymentIntent: holdPi } = await stripe.confirmCardPayment(bundle.hold_client_secret, {
                    payment_method: {
                        card,
                        billing_details: { name: nameEl.value, email: emailEl.value }
                    }
                });
                if (holdError) throw holdError;

                await pingHoldRecorded(holdPi?.id);
            }

            redirectSuccess();
        } catch (err) {
            showError(err.message || 'Payment failed.');
        } finally {
            setLoading(false);
        }
    });

    async function createBundle(amountCents, holdCents, email, name) {
        const res = await fetch(intentUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ amount: amountCents, hold_amount: holdCents, email, name })
        });
        if (!res.ok) throw new Error(await res.text() || 'Could not start payment.');
        return res.json();
    }

    async function pingHoldRecorded(holdPiId) {
        if (!holdPiId) return;
        try {
            await fetch(holdUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ hold_pi_id: holdPiId })
            });
        } catch {}
    }

    function setLoading(loading) {
        btn.disabled = loading;
        spinner.classList.toggle('hidden', !loading);
    }
    function showError(msg) {
        const el = document.getElementById('card-errors');
        el.textContent = msg;
        el.classList.add('text-rose-600');
        el.scrollIntoView({behavior:'smooth', block:'center'});
    }
    function redirectSuccess() {
        const url = new URL(window.location.href);
        const returnTo = url.searchParams.get('return_to');
        if (returnTo) window.location.href = returnTo; else window.location.reload();
    }
})();
</script>
</body>
</html>
