@php
    /** @var \App\Models\Job $job */
    /** @var string $payUrl */
    /** @var ?string $logoCid */
    /** @var string $reference */

    $currency = strtoupper($job->currency ?? optional($job->flow)->currency ?? 'NZD');

    $fmtMoney = function ($cents) use ($currency) {
        if ($cents === null) return null;
        $amount = number_format(((int)$cents) / 100, 2);
        return "{$currency} {$amount}";
    };

    // Try to derive amounts if you store them; fall back safely.
    $total      = $job->total_cents      ?? $job->amount_cents ?? null;
    $paid       = $job->paid_cents       ?? null;
    $remaining  = $job->remaining_cents  ?? (($total !== null && $paid !== null) ? max(0, $total - $paid) : null);
    $hold       = $job->hold_cents       ?? optional($job->flow)->hold_cents ?? null;

    // Dates (show local TZ if set)
    $tz = $job->timezone ?? config('app.timezone', 'UTC');
    $parse = fn($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone($tz) : null;
    $startAt = $parse($job->start_at ?? null);
    $endAt   = $parse($job->end_at ?? null);
    $fmt = fn($c) => $c ? $c->isoFormat('ddd D MMM YYYY, HH:mm') . " ({$tz})" : null;

    // Customer bits
    $custName  = $job->customer_name  ?? optional($job->customer)->name  ?? null;
    $custEmail = $job->customer_email ?? optional($job->customer)->email ?? null;
    $custPhone = $job->customer_phone ?? optional($job->customer)->phone ?? null;

    // Vehicle / product (best-effort)
    $vehicle   = $job->vehicle_name ?? $job->vehicle ?? $job->product_name ?? null;
@endphp

@component('mail::message')
{{-- Logo (CID-embedded if available) --}}
@if(!empty($logoCid))
<p style="text-align:center; margin-bottom: 8px;">
    <img src="{{ $logoCid }}" alt="Logo" style="max-width: 200px; height: auto;">
</p>
@endif

# Complete your payment

Hi{{ $custName ? ' ' . $custName : '' }},  
Please complete payment for your reservation **{{ $reference }}** to secure your booking.

@component('mail::button', ['url' => $payUrl])
Pay securely now
@endcomponent

---

## Reservation Summary

@component('mail::table')
| Field | Details |
|:--|:--|
| **Reservation reference** | **{{ $reference }}** |
@if($custName)
| Name | {{ $custName }} |
@endif
@if($custEmail)
| Email | {{ $custEmail }} |
@endif
@if($custPhone)
| Phone | {{ $custPhone }} |
@endif
@if($vehicle)
| Vehicle | {{ $vehicle }} |
@endif
@if($startAt)
| Start | {{ $fmt($startAt) }} |
@endif
@if($endAt)
| End | {{ $fmt($endAt) }} |
@endif
@if($total !== null)
| Total | {{ $fmtMoney($total) }} |
@endif
@if($paid !== null)
| Paid to date | {{ $fmtMoney($paid) }} |
@endif
@if($remaining !== null)
| **Amount due now** | **{{ $fmtMoney($remaining) }}** |
@endif
@if($hold !== null)
| Security hold (preauth) | {{ $fmtMoney($hold) }} (placed on card, then released) |
@endif
@endcomponent

If the button above doesn’t work, copy and paste this link into your browser:

{{ $payUrl }}

Thanks,  
{{ config('app.name') }}
@endcomponent
