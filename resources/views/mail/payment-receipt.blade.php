{{-- resources/views/mail/payment-receipt.blade.php --}}
@php
    $brandName = $brand->short_name ?? 'Dream Drives';
    $logo = $brand->email_logo_url ?? null;
    $currency = $payment->currency ?? $job->currency ?? 'NZD';
    $fmt = fn ($c) => ($currency === 'NZD' ? 'NZ$' : ($currency === 'AUD' ? 'A$' : '$')) . number_format(($c ?? 0)/100, 2);
    $type = str_replace('_',' ', $payment->type ?? 'payment');
@endphp

@component('mail::message')
@if($logo)
<p style="text-align:center;margin:0 0 12px">
  <img src="{{ $logo }}" alt="{{ $brandName }}" style="max-height:42px">
</p>
@endif

# Payment received — {{ ucfirst($type) }}

Thanks {{ $job->customer_name ?? '' }}, we’ve received your payment for **Job #{{ $job->id }}**.

**Receipt**
- Amount: **{{ $fmt($payment->amount_cents) }}** {{ $currency }}
- Type: {{ ucfirst($type) }}
- Reference: {{ $payment->reference ?? 'N/A' }}
- Status: {{ ucfirst($payment->status ?? 'succeeded') }}
- Date: {{ optional($payment->created_at)->format('d M Y H:i') }}

@if(!empty($payment->stripe_charge_id))
You’ll also receive a Stripe receipt to your email.
@endif

If you need a PDF invoice, just reply to this email and we’ll send one through.

Thanks,  
**{{ $brandName }}**
@endcomponent
