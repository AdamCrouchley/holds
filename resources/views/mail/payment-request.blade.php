@php
    $brandName = $brand->short_name ?? 'Dream Drives';
    $logo = $brand->email_logo_url ?? null;
    $currency = $job->currency ?? 'NZD';
    $fmt = fn ($c) => ($currency === 'NZD' ? 'NZ$' : ($currency === 'AUD' ? 'A$' : '$')) . number_format(($c ?? 0)/100, 2);
    $label = $purpose ? ucfirst($purpose) : 'Payment';
@endphp

@component('mail::message')
@if($logo)
<p style="text-align:center;margin:0 0 12px">
  <img src="{{ $logo }}" alt="{{ $brandName }}" style="max-height:42px">
</p>
@endif

# {{ $label }} requested

Hi {{ $job->customer_name ?? 'there' }},

We’ve set up a secure payment link for **Job #{{ $job->id }}** ({{ $brandName }}).
@isset($amountCents)
Please pay **{{ $fmt($amountCents) }}** to continue.
@else
Please follow the link below to complete payment.
@endisset

@component('mail::button', ['url' => $payUrl])
Pay securely
@endcomponent

If the button doesn’t work, copy and paste this link:
<br><span style="word-break:break-all">{{ $payUrl }}</span>

**Summary**
- Job: #{{ $job->id }}
- Customer: {{ $job->customer_name ?? 'Unknown' }}
- Dates: {{ optional($job->start_at)->format('d M Y') }} → {{ optional($job->end_at)->format('d M Y') }}
@isset($amountCents)
- Amount due: {{ $fmt($amountCents) }}
@endisset

If your booking details change, we may issue a new link.

Thanks,  
**{{ $brandName }}**

<small>This is an automated message from our payment system.</small>
@endcomponent
