@component('mail::message')
# Kia ora {{ $customer->first_name ?? 'there' }},

@isset($note)
{{ $note }}

@endisset

Use the button below to access your customer portal.

@component('mail::button', ['url' => $link])
Open Customer Portal
@endcomponent

If the button doesn’t work, copy and paste this link into your browser:

{{ $link }}

Thanks,  
{{ config('app.name') }}
@endcomponent
