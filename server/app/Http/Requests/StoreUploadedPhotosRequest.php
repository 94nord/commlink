<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a stranger holding a link is allowed to hand in.
 *
 * Everything here is checked server-side on purpose. This request arrives
 * with no session and no account behind it, so the browser's `accept`
 * attribute and the declared content type are decoration — `mimes` is what
 * actually looks at the bytes (Laravel checks the real file signature via
 * Fileinfo, not the extension or the client's claimed type).
 */
class StoreUploadedPhotosRequest extends FormRequest
{
    /**
     * Holding a usable token is the authorization; the route has already
     * resolved one or answered 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            // Images plus PDF: the realistic range for a trades firm
            // (photos of the job, plus an invoice or certificate) without
            // opening this, the one unauthenticated endpoint in the app, to
            // office-document macros or arbitrary files.
            'photos.*' => ['required', 'mimes:jpg,jpeg,png,webp,pdf'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $maxBytes = (int) config('inbox.uploads.max_total_kilobytes') * 1024;
            $totalBytes = array_sum(array_map(
                fn ($photo) => $photo->getSize(),
                $this->file('photos') ?? [],
            ));

            if ($totalBytes > $maxBytes) {
                $validator->errors()->add('photos', __('inbox.upload_too_large', [
                    'max' => (int) config('inbox.uploads.max_total_kilobytes'),
                ]));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.*.mimes' => __('inbox.not_a_supported_file'),
        ];
    }
}
