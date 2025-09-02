{{-- resources/views/mail/booking/payment-link.blade.php --}}
@component('mail::message')
# Complete your booking payment

Booking **{{ $booking->reference }}**  
Amount due: **NZ$ {{ number_format(($booking->total_amount - ($booking->payments?->whereIn('status',['succeeded','paid','captured','completed'])->sum('amount') ?? 0))/100, 2) }}**

@component('mail::button', ['url' => $url])
Pay now
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent
