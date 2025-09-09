{{-- resources/views/mail/hold-released.blade.php --}}
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

# Bond hold released

Good news — the **{{ $fmt($holdCents) }}** bond hold for **Job #{{ $job->id }}** has been released.  
No charges were captured from this hold.

Banks can take a little time to reflect the release on your statement.

Thanks,  
**{{ $brandName }}**
@endcomponent
