<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationMessageRequest extends FormRequest
{
    /**
     * How long a message may be is the channel's business, not this
     * request's — SendOutboundMessage enforces it against the adapter's
     * declared capabilities once it knows which channel the reply is
     * going out on.
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            // What the composer produced, where the channel carries
            // formatting. Never trusted: SendOutboundMessage sanitizes it
            // and derives `body` from it.
            'bodyHtml' => ['nullable', 'string'],
            // Selector B: which exchange this answers. Absent means the one
            // the customer last wrote on. Ownership is checked in the
            // controller, against this conversation's own threads.
            'threadId' => ['nullable', 'integer'],
            // Or a new exchange, which needs a name on a channel that names
            // them.
            'newThread' => ['nullable', 'boolean'],
            // Which of the customer's addresses the new exchange starts on.
            // Absent means whichever they last wrote from. Ownership is
            // checked in the controller.
            'identityId' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:255', 'required_if:newThread,true'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required_if' => __('inbox.subject_required'),
        ];
    }
}
