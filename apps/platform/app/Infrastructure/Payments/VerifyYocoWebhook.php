<?php

namespace App\Infrastructure\Payments;

use Illuminate\Http\Request;

final class VerifyYocoWebhook
{
    public function execute(Request $r): array
    {
        $a = app(YocoAccount::class)->configuration();
        $id = $r->header('webhook-id', '');
        $ts = $r->header('webhook-timestamp', '');
        abort_unless($id !== '' && strlen($id) <= 160 && ctype_digit($ts) && abs(now()->timestamp - (int) $ts) <= 180 && strlen($r->getContent()) <= 65536, 403);
        $secret = base64_decode(substr($a['webhook_secret'], 6), true);
        abort_unless($secret !== false && strlen($secret) >= 16, 503);
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$r->getContent(), $secret, true));
        $valid = false;
        foreach (explode(' ', $r->header('webhook-signature', '')) as $sig) {
            if (str_starts_with($sig, 'v1,') && hash_equals($expected, substr($sig, 3))) {
                $valid = true;
            }
        }
        abort_unless($valid, 403);
        $event = json_decode($r->getContent(), true);
        abort_unless(is_array($event), 422);

        return $event;
    }
}
