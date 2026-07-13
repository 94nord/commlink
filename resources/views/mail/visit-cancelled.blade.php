<x-mail::message>
# Your visit has been cancelled

Hi {{ $customerName }},

@if ($address)
Your visit at **{{ $address }}** on **{{ $startsAt }}** has been cancelled.
@else
Your visit on **{{ $startsAt }}** has been cancelled.
@endif

If you have questions, reply to this email.

Thanks,<br>
{{ $corporationName }}

@include('mail.partials.footer-logo')
</x-mail::message>
