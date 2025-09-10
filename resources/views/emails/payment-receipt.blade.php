@php
    /** @var \App\Models\Job $job */
    /** @var \App\Models\Payment|null $payment */
    /** @var string $logoUrl */
    /** @var string $viewUrl */
    /** @var string $reference */
    /** @var string $currency */
    /** @var ?string $amountDisplay */
    /** @var ?string $paidAtTz */
    /** @var ?string $cardBrand */
    /** @var ?string $cardLast4 */
    /** @var ?string $provider */
    /** @var ?string $providerId */
    /** @var ?string $receiptUrl */

    $custName  = $job->customer_name  ?? optional($job->customer)->name  ?? null;
    $custEmail = $job->customer_email ?? optional($job->customer)->email ?? null;
    $custPhone = $job->customer_phone ?? optional($job->customer)->phone ?? null;

    $tz = $job->timezone ?? config('app.timezone', 'UTC');
@endphp

@component('mail::message')

{{-- Logo --}}
<p style="text-align:center;margin:0 0 8px;">
  <img src="{{ $logoUrl }}" alt="Dream Drives" style="max-width:200px;height:auto;">
</p>

# Payment received — thank you!

Hi{{ $custName ? ' ' . $custName : '' }},  
We’ve received your payment for **Booking {{ $reference }}**.

{{-- Big "View Booking" button --}}
<p style="text-align:center;margin: 20px 0;">
  <a href="{{ $viewUrl }}"
     style="background-color:#2d3748;
            color:#ffffff;
            padding:14px 32px;
            font-size:18px;
            font-weight:bold;
            text-decoration:none;
            border-radius:6px;
            display:inline-block;">
    View Booking / Receipt
  </a>
</p>

## Payment Summary

@component('mail::table')
| Field | Details |
|:--|:--|
@if($amountDisplay)
| **Amount paid** | **{{ $amountDisplay }}** |
@endif
@if($paidAtTz)
| Paid at | {{ $paidAtTz }} |
@endif
@if($cardBrand || $cardLast4)
| Card | {{ trim(($cardBrand ? strtoupper($cardBrand) : '').($cardLast4 ? ' •••• ' . $cardLast4 : '')) }} |
@endif
| Booking Reference | **{{ $reference }}** |
@if($custEmail)
| Email | {{ $custEmail }} |
@endif
@if($custPhone)
| Phone | {{ $custPhone }} |
@endif
@if($providerId)
| Transaction ID | {{ $providerId }} ({{ $provider }}) |
@endif
@if($receiptUrl)
| Payment provider receipt | <a href="{{ $receiptUrl }}">View provider receipt</a> |
@endif
@endcomponent

If the button doesn’t work, open this link:
{{ $viewUrl }}

Thanks,  
{{ config('app.name') }}

@endcomponent
