@php
    /** @var string $logoUrl */
@endphp

{{-- Logo via public URL --}}
<p style="text-align:center;margin:0 0 8px;">
    <img src="{{ $logoUrl }}" alt="Dream Drives" style="max-width:200px;height:auto;">
</p>

# Complete your payment

Hi{{ $job->customer_name ? ' ' . $job->customer_name : '' }},  
Please complete payment for your reservation **{{ $reference }}** to secure your booking.

@component('mail::button', ['url' => $payUrl])
Pay securely now
@endcomponent
