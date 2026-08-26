<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that came in with a message, stored as our own copy on the
 * configured disk. Nothing here names a channel: MMS puts pictures in it
 * now, and a richer channel will put its own files in the same shape later.
 */
class MessageAttachment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'message_id',
        'content_type',
        'filename',
        'path',
        'byte_size',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
