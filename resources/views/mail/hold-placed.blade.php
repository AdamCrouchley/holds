{{-- resources/views/mail/hold-placed.blade.php --}}
@php
    $brandName = $brand->short_name ?? 'Dream Drives';
    $logo = $brand->email_logo_url ?? null;
    $currency = $currency ?? 'NZD';
    $fmt = fn ($c) => ($currency === 'NZD' ? 'NZ$' : ($currency === 'AUD' ? 'A$' : '$')) . number_format(($c ?? 0)/100, 2);
@endphp

@component('mail::message')
@if($logo)
<p style="text-align:center;margin:0 0 12px">
  <img src="{{ $logo }}" alt="{{ $brandName }}" style="max-height:42px">
</p>
@endif

# Bond hold placed

We’ve placed a temporary **bond hold of {{ $fmt($holdCents) }}** on your card for **Job #{{ $job->id }}**.  
This is **not a charge**. It simply reserves funds during your hire.

**What happens next**
- The hold will automatically release after your hire (bank timings vary).  
@isset($expectedReleaseNote)
- Typical release timeframe: {{ $expectedReleaseNote }}.
@endisset

If you have questions, just reply to this email.

Thanks,  
**{{ $brandName }}**
@endcomponent
