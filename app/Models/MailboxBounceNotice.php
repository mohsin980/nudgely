<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox message already examined for bounce notices. Keeps the outcome only, never the message content.
 */
#[Fillable(['organization_id', 'email_connection_id', 'message_id', 'provider_message_id', 'outcome'])]
class MailboxBounceNotice extends Model
{
    public const UPDATED_AT = null;
}
