{{-- Corporation logo at the foot of every email. Embedded inline (CID) so it
     renders in any client without a publicly reachable URL. $logo is the raw
     image bytes, or null when the corporation has no logo. --}}
@isset($logo)
@if($logo)
<x-mail::subcopy>
<img src="{{ $message->embedData($logo, 'logo.png') }}" alt="{{ $corporationName }}" style="max-height:48px;">
</x-mail::subcopy>
@endif
@endisset
