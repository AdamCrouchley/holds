@component('mail::message')
# Sign in to your portal

Hi {{ $customer->name ?: $customer->email }},

Click the button below to sign in:

@component('mail::button', ['url' => $url])
Sign in
@endcomponent

This link expires in 15 minutes. If you didn’t request this, ignore this email.

Thanks,  
{{ config('app.name') }}
@endcomponent
