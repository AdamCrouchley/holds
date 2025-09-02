<p>Hi {{ $booking->customer->name ?? 'there' }},</p>
<p>
We’ve created a {{ $pr->type }} payment request
@isset($amount) for <strong>${{ $amount }}</strong>@endisset
for booking <strong>{{ $booking->reference }}</strong>.
</p>
<p>Please complete it here:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>
<p>Thanks!</p>
