<?php

namespace App\Http\Controllers\Outreach;

use App\Http\Controllers\Controller;
use App\Models\OutreachContact;
use Illuminate\Http\Response;

/**
 * One-click unsubscribe from outreach email. The link is signed, so it can't be guessed or altered. It only ever stops
 * email; it doesn't change anything else about the contact.
 */
class OutreachUnsubscribeController extends Controller
{
    public function __invoke(OutreachContact $contact): Response
    {
        if ($contact->email_opted_out_at === null) {
            $contact->forceFill(['email_opted_out_at' => now()])->save();
        }

        return response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribed</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#0f172a">'
            .'<h1 style="font-size:1.5rem">You are unsubscribed</h1><p>You will not receive more emails from us.</p></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
}
