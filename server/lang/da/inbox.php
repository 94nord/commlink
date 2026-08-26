<?php

return [

    'say_who_this_is_going_to' => 'Angiv, hvem beskeden skal gå til.',
    'invalid_phone' => 'Det ligner ikke et telefonnummer, vi kan sende sms til.',
    'no_number_for_customer' => 'Vi har endnu ikke et nummer på denne kunde — tilføj et for at skrive til dem.',
    'conversation_already_linked' => 'Denne samtale tilhører allerede :name. Fjern de forkerte numre fra dem i stedet.',
    'message_too_long' => 'Det er for langt til at sende som :channel — højst :limit tegn.',
    'nothing_to_unlink' => 'Der er intet at fjerne — denne person er kun denne adresse.',
    'channel_not_available' => 'Denne virksomhed kan ikke sende via :channel — kanalen er enten ikke tilmeldt, mangler en afsenderadresse eller er ikke verificeret endnu.',
    'not_an_address' => 'Det er hverken et telefonnummer eller en e-mailadresse.',
    'nothing_to_send' => 'Der er ikke noget at sende.',
    'subject_required' => 'Giv den nye samtale et emne.',
    'channel_has_one_thread' => 'Denne kanal har én samtale per adresse, så der er ikke en ny at starte.',
    'module_not_enabled' => 'Denne virksomhed er ikke tilmeldt :module, så der kan ikke sendes noget der. Tidligere beskeder kan stadig læses.',
    'no_email_address' => 'Denne virksomhed har endnu ikke en e-mailadresse og kan derfor ikke sende mails. Tilføj en med `php artisan app:attach-address --type=email`.',
    'default_subject' => 'Besked fra din håndværker',
    'no_sms_number' => 'Denne virksomhed har endnu ikke et sms-nummer og kan derfor ikke sende beskeder. Tilføj et med `php artisan app:attach-address`.',
    'no_address_to_reply_to' => 'Der er ingen adresse tilbage i denne samtale at svare på.',
    'sms_refused' => 'Beskeden blev ikke sendt — sms-udbyderen afviste den: :reason',
    'not_a_supported_file' => 'Den fil kan vi ikke modtage. Brug JPG, PNG, WEBP eller PDF.',
    'upload_too_large' => 'Det er for meget at sende på én gang — højst :max kilobyte i alt. Send resten i endnu en omgang.',

];
