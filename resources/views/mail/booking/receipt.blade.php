{{-- resources/views/mail/booking/receipt.blade.php --}}
@component('mail::message')
# Payment received

We received **NZ$ {{ number_format($amount/100, 2) }}** for booking **{{ $booking->reference }}**.

Thanks!  
{{ config('app.name') }}
@endcomponent
