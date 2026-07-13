<x-mail::message>
# Your visit has been moved

Hi {{ $customerName }},

@if ($address)
Your visit at **{{ $address }}** has been moved:
@else
Your visit has been moved:
@endif

~~{{ $previousStartsAt }}~~<br>
**{{ $startsAt }} – {{ $endsAt }}**

@if ($bookingUrl)
<x-mail::button :url="$bookingUrl">
View or change your booking
</x-mail::button>
@else
If the new time does not suit you, reply to this email and we will find
another one.
@endif

Thanks,<br>
{{ $corporationName }}

@include('mail.partials.footer-logo')
</x-mail::message>
