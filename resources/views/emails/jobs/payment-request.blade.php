@component('mail::message')
# Payment Request

Hello {{ $job->customer_name ?? 'Customer' }},

Please complete your payment here:

@component('mail::button', ['url' => $payUrl])
Pay Now
@endcomponent

Thanks,  
{{ config('app.name') }}
@endcomponent
