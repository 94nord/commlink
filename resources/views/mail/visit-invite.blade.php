<x-mail::message>
# Book your {{ $type }} visit

Hi {{ $customerName }},

{{ $corporationName }} has opened booking for a {{ $type }} visit at
**{{ $address }}**. Pick the time that suits you — you can book and
reschedule until **{{ $closeOffOn }}**.

<x-mail::button :url="$bookingUrl">
Choose your time
</x-mail::button>

This link is personal to you; no account is needed.

Thanks,<br>
{{ $corporationName }}

@include('mail.partials.footer-logo')
</x-mail::message>
