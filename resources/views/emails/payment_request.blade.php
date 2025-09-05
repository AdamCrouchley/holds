@component('mail::message')
# Payment Request

Hi {{ $job->customer_name ?? 'there' }},

Please complete the payment for your booking **{{ $job->reference ?? $job->id }}**.

**Amount due:** ${{ $amount }}

@component('mail::button', ['url' => $payUrl])
Pay Now
@endcomponent

If the button doesn’t work, copy and paste this link into your browser:  
{{ $payUrl }}

Thanks,  
{{ config('app.name') }}
@endcomponent
