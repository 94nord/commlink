<?php

return [

    'say_who_this_is_going_to' => 'Say who this is going to.',
    'invalid_phone' => 'That does not look like a phone number we can text.',
    'no_number_for_customer' => 'We have no number for this customer yet — add one to write to them.',
    'conversation_already_linked' => 'This conversation already belongs to :name. Take the wrong addresses off them instead.',
    'message_too_long' => 'That is too long to send as :channel — :limit characters at most.',
    'nothing_to_unlink' => 'There is nothing to unlink — this person is only this address.',
    'channel_not_available' => 'This corporation cannot send on :channel — the channel is either not switched on, has no address to send from, or is not verified yet.',
    'not_an_address' => 'That is neither a phone number nor an email address.',
    'nothing_to_send' => 'There is nothing to send.',
    'subject_required' => 'Give the new conversation a subject.',
    'channel_has_one_thread' => 'This channel keeps one conversation per address, so there is no new one to start.',
    'module_not_enabled' => 'This corporation is not switched on for :module, so nothing can be sent there. Existing messages stay readable.',
    'no_email_address' => 'This corporation has no email address yet, so it cannot send mail. Attach one with `php artisan app:attach-address --type=email`.',
    'default_subject' => 'Message from your tradesperson',
    'no_sms_number' => 'This corporation has no SMS number yet, so it cannot send texts. Attach one with `php artisan app:attach-address`.',
    'no_address_to_reply_to' => 'There is no address left in this conversation to reply to.',
    'sms_refused' => 'The text was not sent — the SMS provider refused it: :reason',
    'not_a_supported_file' => 'That file is not something we can accept. Use a JPG, PNG, WEBP or PDF.',
    'upload_too_large' => 'That is too much to send at once — :max kilobytes in total. Send the rest in a second batch.',

];
