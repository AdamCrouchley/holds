{{-- resources/views/portal/pay.blade.php --}}
@php
    /** Publishable key for Stripe.js (safe to expose) */
    $stripePk = config('services.stripe.key') ?: env('STRIPE_KEY');

    /** Context */
    $tz       = $user->portal_timezone ?? config('app.timezone');
    $cur      = $booking->currency ?? 'NZD';
    $fmtMoney = fn ($cents) => ($cur ?: 'NZD') . ' ' . number_format(((int)($cents ?? 0))/100, 2);

    /** Money (all cents) */
    $total    = (int) ($total   ?? (int)($booking->total_amount   ?? 0));
    $paid     = (int) ($paid    ?? (int)($booking->amount_paid    ?? 0));
    $balance  = (int) ($balance ?? max(0, $total - $paid));

    /** Bond (hold) */
    $holdAmt  = (int) ($booking->hold_amount ?? 0);

    /** Booking meta */
    $ref      = $booking->reference ?? ('BK'.str_pad((string)$booking->id, 6, '0', STR_PAD_LEFT));
    $start    = optional($booking->start_at)->timezone($tz);
    $end      = optional($booking->end_at)->timezone($tz);

    /** Customer info (optional) */
    $cust     = $booking->customer ?? null;
    $custName = trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: ($user->name ?? 'Guest');
    $custEmail= $cust->email ?? ($user->email ?? null);

    /** Optional helpers */
    $claimEnabled = (bool)($booking->can_claim ?? false);
    $tokenPayUrl  = method_exists($booking, 'getPortalUrlAttribute') ? $booking->portal_url : null;

    /** Feature flags */
    $enablePaymentRequest = true;  // Apple/Google Pay (only for full balance)
@endphp

<!doctype html>
<html lang="en" class="h-full bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay for Booking {{ $ref }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
    <style>
        /* Glass + soft shadows */
        .glass { background: rgba(255,255,255,.72); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .dark .glass { background: rgba(2,6,23,.5); }
        .elevate { box-shadow: 0 10px 30px -12px rgba(2,6,23,.25), 0 8px 16px -12px rgba(2,6,23,.18); }
        .ring-subtle { box-shadow: inset 0 0 0 1px rgba(15,23,42,.06); }
        .dark .ring-subtle { box-shadow: inset 0 0 0 1px rgba(148,163,184,.15); }
        .hero-grad {
            background: radial-gradient(1200px 500px at 10% -10%, rgba(99,102,241,.20), transparent 60%),
                        radial-gradient(1200px 500px at 100% 0%, rgba(56,189,248,.18), transparent 60%),
                        linear-gradient(180deg, rgba(255,255,255,.75), rgba(255,255,255,.65));
        }
        .dark .hero-grad {
            background: radial-gradient(1200px 500px at 10% -10%, rgba(99,102,241,.18), transparent 60%),
                        radial-gradient(1200px 500px at 100% 0%, rgba(56,189,248,.16), transparent 60%),
                        linear-gradient(180deg, rgba(2,6,23,.6), rgba(2,6,23,.5));
        }
        .chip { display:inline-flex;align-items:center;gap:.5rem;padding:.375rem .7rem;border-radius:9999px; box-shadow: inset 0 0 0 1px rgba(15,23,42,.08); }
        .dark .chip { box-shadow: inset 0 0 0 1px rgba(148,163,184,.2); }
        .btn-primary:disabled { opacity:.6; cursor:not-allowed; }
        .smooth { transition: all .2s ease; }
        .hidden-important { display:none !important; }
    </style>
</head>
<body class="h-full font-sans text-slate-900 dark:text-slate-100 antialiased selection:bg-indigo-200/60 dark:selection:bg-indigo-500/30">
<div class="min-h-screen">

    {{-- Top / hero --}}
    <header class="hero-grad border-b border-white/40 dark:border-slate-800/60">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 py-6 sm:py-8 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="h-11 w-11 rounded-2xl grid place-content-center bg-indigo-600 text-white shadow-md">
                    <span class="font-semibold">DD</span>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Booking</div>
                    <div class="flex items-center gap-2">
                        <div class="text-lg font-semibold">#{{ $ref }}</div>
                        <span class="chip text-xs bg-white/70 dark:bg-slate-900/40">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor"><path d="M9 12.75 11.25 15 15 9.75" /><path fill-rule="evenodd" d="M2.25 12a9.75 9.75 0 1 1 19.5 0 9.75 9.75 0 0 1-19.5 0Zm9.75-8.25a8.25 8.25 0 1 0 0 16.5 8.25 8.25 0 0 0 0-16.5Z" clip-rule="evenodd"/></svg>
                            Secure checkout
                        </span>
                    </div>
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
                <h2 class="text-lg font-semibold mb-3">Booking Summary</h2>

                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-slate-500 dark:text-slate-400">Reference</dt>
                    <dd class="text-right font-medium">{{ $ref }}</dd>

                    @if($start)
                        <dt class="text-slate-500 dark:text-slate-400">Start</dt>
                        <dd class="text-right font-medium">{{ $start->format('D, M j, Y H:i') }}</dd>
                    @endif
                    @if($end)
                        <dt class="text-slate-500 dark:text-slate-400">End</dt>
                        <dd class="text-right font-medium">{{ $end->format('D, M j, Y H:i') }}</dd>
                    @endif

                    <dt class="text-slate-500 dark:text-slate-400">Total</dt>
                    <dd class="text-right font-medium">{{ $fmtMoney($total) }}</dd>

                    <dt class="text-slate-500 dark:text-slate-400">Paid</dt>
                    <dd class="text-right font-medium">{{ $fmtMoney($paid) }}</dd>

                    <dt class="text-slate-500 dark:text-slate-400">Balance</dt>
                    <dd class="text-right text-base font-semibold">{{ $fmtMoney($balance) }}</dd>
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
                                The hold is set to automatically cancel <span class="font-semibold">48 hours after</span> your booking ends.
                            </p>
                        </div>
                    </div>
                @endif

                @if(!empty($payments) && count($payments))
                    <div class="mt-5">
                        <h3 class="text-sm font-semibold mb-2">Previous Payments</h3>
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-700/60 divide-y divide-slate-200/70 dark:divide-slate-700/60 bg-white/60 dark:bg-slate-900/40">
                            @foreach($payments as $p)
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

                <ul class="mt-4 list-disc list-inside text-xs text-slate-500 dark:text-slate-400 space-y-1">
                    <li>Payments are processed securely by Stripe.</li>
                    <li>Your card details never touch our servers.</li>
                    <li>All amounts shown in {{ $cur }}.</li>
                </ul>
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
                    <input type="hidden" id="booking-id" value="{{ $booking->id }}">
                    <input type="hidden" id="balance-cents" value="{{ $balance }}">
                    <input type="hidden" id="hold-cents" value="{{ $holdAmt }}">

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm text-slate-600 dark:text-slate-300">Name on card</span>
                            <input type="text" id="payer-name" value="{{ $custName }}"
                                   class="mt-1 w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white/80 dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 focus:border-indigo-500 focus:ring-indigo-500 smooth"
                                   autocomplete="cc-name" required>
                        </label>
                        <label class="block">
                            <span class="text-sm text-slate-600 dark:text-slate-300">Email for receipt</span>
                            <input type="email" id="payer-email" value="{{ $custEmail }}"
                                   class="mt-1 w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white/80 dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 focus:border-indigo-500 focus:ring-indigo-500 smooth"
                                   autocomplete="email" required>
                        </label>
                    </div>

                    {{-- Fixed amount notice (no increments allowed) --}}
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        You will be charged the outstanding balance of
                        <span class="font-semibold">{{ $fmtMoney($balance) }}</span>.
                    </p>

                    {{-- Payment Request (Apple/Google Pay) --}}
                    <div id="payment-request-container" class="{{ $enablePaymentRequest ? '':'hidden-important' }}">
                        <div id="payment-request-button" class="mb-3"></div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 -mt-2 mb-2">Or pay with card below</div>
                    </div>

                    {{-- Payment Element (card + wallets fallback) --}}
                    <div>
                        <span class="text-sm text-slate-600 dark:text-slate-300">Card details</span>
<div id="card-element" class="mt-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white/80 dark:bg-slate-900/40 p-3"></div>
<div id="card-errors" role="alert" class="mt-2 text-sm text-rose-600"></div>
                    </div>

                    {{-- Consent / explainer --}}
                    <div class="rounded-xl bg-slate-50/80 dark:bg-slate-900/40 ring-subtle p-4 text-xs leading-6 text-slate-600 dark:text-slate-300">
                        <p>By paying, you agree that:</p>
                        <ul class="list-disc list-inside mt-1 space-y-1">
                            <li>We’ll place a temporary hold of <span class="font-semibold">{{ $fmtMoney($holdAmt) }}</span> on your card.</li>
                            <li>Your card will be saved to cover any post-hire charges (fuel, tolls, damage) as per our terms.</li>
                            <li>The hold automatically cancels 48 hours after your booking ends unless otherwise required.</li>
                        </ul>
                    </div>

                    <div class="flex items-center justify-between">
                        @if($claimEnabled)
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
                            <span id="pay-label">Pay {{ $fmtMoney($balance) }}</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Trust footer block --}}
            <div class="mt-4 text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2">
                <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12.75 11.25 15 15 9.75" /><path fill-rule="evenodd" d="M2.25 12a9.75 9.75 0 1 1 19.5 0 9.75 9.75 0 0 1-19.5 0Zm9.75-8.25a8.25 8.25 0 1 0 0 16.5 8.25 8.25 0 0 0 0-16.5Z" clip-rule="evenodd"/></svg>
                Payments handled by Stripe. {{ config('app.name') }} never stores your card details.
            </div>
        </section>
    </main>

    {{-- Footer --}}
    <footer class="py-10 text-center text-xs text-slate-500 dark:text-slate-400">
        © {{ date('Y') }} {{ config('app.name') }}
    </footer>
</div>

{{-- Stripe --}}
<script src="https://js.stripe.com/v3/"></script>
<script>
(() => {
    const stripe = Stripe(@json($stripePk));
    const form   = document.getElementById('pay-form');
    const btn    = document.getElementById('pay-btn');
    const spinner= document.getElementById('pay-spinner');
    const label  = document.getElementById('pay-label');
    const nameEl = document.getElementById('payer-name');
    const emailEl= document.getElementById('payer-email');

    const balanceCents = parseInt(document.getElementById('balance-cents').value || '0', 10);
    const holdCents    = parseInt(document.getElementById('hold-cents').value || '0', 10);
    const bookingId    = document.getElementById('booking-id').value;

    // Elements
// Elements (Card Element doesn't need a clientSecret to render)
const elements = stripe.elements({
  appearance: { theme: document.documentElement.classList.contains('dark') ? 'night' : 'flat' }
});
const card = elements.create('card');
card.mount('#card-element');

card.on('change', (e) => {
  document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  setLoading(true);

  try {
    // Ask server for BOTH client secrets: charge PI and hold PI
    const bundle = await createBundle(balanceCents, holdCents, emailEl.value, nameEl.value);

    // 1) Confirm the CHARGE PI — pay the balance and save card for off-session
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

    // 2) Confirm the HOLD PI — authorize the $holdAmt with manual capture
    if (holdCents > 0 && bundle.hold_client_secret) {
      const { error: holdError, paymentIntent: holdPi } = await stripe.confirmCardPayment(bundle.hold_client_secret, {
        payment_method: {
          card,
          billing_details: { name: nameEl.value, email: emailEl.value }
        }
      });
      if (holdError) throw holdError;

      // Optional: tell server we have a live hold for bookkeeping/UI
      await pingHoldRecorded(holdPi?.id);
    }

    redirectSuccess();
  } catch (err) {
    showError(err.message || 'Payment failed.');
  } finally {
    setLoading(false);
  }
});


    // Payment Request (Apple/Google Pay) — fixed total only
    const pr = stripe.paymentRequest({
        country: 'NZ',
        currency: @json(strtolower($cur)),
        total: { label: 'Booking {{ $ref }}', amount: balanceCents },
        requestPayerName: true,
        requestPayerEmail: true,
    });

    if (@json($enablePaymentRequest)) {
        pr.canMakePayment().then(res => {
            if (res) {
                const prButton = elements.create('paymentRequestButton', { paymentRequest: pr });
                document.getElementById('payment-request-container').classList.remove('hidden-important');
                prButton.mount('#payment-request-button');

                pr.on('paymentmethod', async (ev) => {
                    try {
                        const bundle = await createBundle(balanceCents, holdCents, emailEl.value, nameEl.value);
                        // 1) Confirm the charge PI (saves card for off-session)
                        const {error: payErr, paymentIntent: payPi} = await stripe.confirmCardPayment(bundle.pay_client_secret, {
                            payment_method: ev.paymentMethod.id
                        }, {handleActions: true});

                        if (payErr) { ev.complete('fail'); return showError(payErr.message || 'Payment failed.'); }

                        // 2) If a hold is required, confirm the hold PI with the same wallet payment method
                        if (holdCents > 0 && bundle.hold_client_secret) {
                            const {error: holdErr, paymentIntent: holdPi} = await stripe.confirmCardPayment(bundle.hold_client_secret, {
                                payment_method: ev.paymentMethod.id
                            }, {handleActions: true});

                            if (holdErr) { ev.complete('fail'); return showError(holdErr.message || 'Hold authorization failed.'); }
                        }

                        ev.complete('success');
                        return redirectSuccess();
                    } catch (err) {
                        ev.complete('fail');
                        showError(err.message || 'Payment failed.');
                    }
                });
            }
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setLoading(true);

        try {
            // Ask the server for BOTH client secrets: charge PI (with setup_future_usage) and hold PI (manual capture)
            const bundle = await createBundle(balanceCents, holdCents, emailEl.value, nameEl.value);

            // 1) Confirm the CHARGE PI — this pays the balance and saves card for off-session use
            const {error: payError, paymentIntent: payPi} = await stripe.confirmPayment({
                elements,
                clientSecret: bundle.pay_client_secret,
                confirmParams: {
                    receipt_email: emailEl.value,
                    payment_method_data: { billing_details: { name: nameEl.value, email: emailEl.value } },
                    // return_url not needed since we stay on-page and handle actions here
                },
                redirect: 'if_required',
            });
            if (payError) throw payError;
            if (!payPi || (payPi.status !== 'succeeded' && payPi.status !== 'requires_capture' && payPi.status !== 'processing'))
                throw new Error('Payment could not be completed.');

            // 2) Confirm the HOLD PI — this authorizes the $holdAmt with manual capture
            if (holdCents > 0 && bundle.hold_client_secret) {
                const {error: holdError, paymentIntent: holdPi} = await stripe.confirmPayment({
                    elements,
                    clientSecret: bundle.hold_client_secret,
                    confirmParams: {
                        payment_method_data: { billing_details: { name: nameEl.value, email: emailEl.value } },
                    },
                    redirect: 'if_required',
                });
                if (holdError) throw holdError;

                // Optional: inform server we got a hold (for bookkeeping/UI flags)
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
const res = await fetch(@json(route('portal.intent', $booking)), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                amount: amountCents,
                hold_amount: holdCents,
                email,
                name
            })
        });
        if (!res.ok) throw new Error(await res.text() || 'Could not start payment.');
        return res.json();
    }

    async function pingHoldRecorded(holdPiId) {
        if (!holdPiId) return;
        try {
            await fetch(@json(route('portal.pay.hold-recorded', ['booking' => $booking->id])), {
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
        // Prefer server-driven redirect param if provided; else reload
        const url = new URL(window.location.href);
        const returnTo = url.searchParams.get('return_to');
        if (returnTo) window.location.href = returnTo; else window.location.reload();
    }
})();
</script>
</body>
</html>
