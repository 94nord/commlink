<x-mail::message>
# Your visit is booked

Hi {{ $customerName }},

@if ($address)
Your visit at **{{ $address }}** is confirmed:
@else
Your visit is confirmed:
@endif

**{{ $startsAt }} – {{ $endsAt }}**

@if ($bookingUrl)
<x-mail::button :url="$bookingUrl">
View or change your booking
</x-mail::button>
@else
If this time does not suit you, reply to this email and we will find
another one.
@endif

Thanks,<br>
{{ $corporationName }}

@include('mail.partials.footer-logo')
</x-mail::message>
