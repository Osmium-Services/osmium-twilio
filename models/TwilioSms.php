<?php

declare(strict_types=1);

namespace Osmium\Services\Twilio\Models;

/**
 * Sends SMS through Twilio. A capability only: it never decides when to
 * text. Core or another service asks through the sms.send hook.
 *
 * sms.send - handle(); payload: to (string, any common phone format), body
 *            (string). Returns null when this service is off or not
 *            configured (so the caller can tell nothing handled it), else
 *            ['sent' => bool, 'detail' => string]. A number that cannot be
 *            made valid is a failed send, not an exception.
 *
 * Whether and when to text (for example only in production) is the caller's
 * decision, since sending costs money.
 */
class TwilioSms
{
    private const MESSAGES_URL = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    /**
     * @param array<string, mixed> $payload
     * @return array{sent: bool, detail: string}|null
     */
    public static function send(array $payload): ?array
    {
        $config = TwilioConfig::get();
        $active = ($config->enabled ?? false) && self::isConfigured($config);
        if (!$active) return null;

        $to = self::toE164((string) ($payload['to'] ?? ''));
        if ($to === '') return ['sent' => false, 'detail' => 'error: not a valid phone number'];

        $result = self::deliver(config: $config, to: $to, body: (string) ($payload['body'] ?? ''));

        return ['sent' => \str_starts_with($result, 'ok'), 'detail' => $result];
    }

    /**
     * UK-focused: a leading 0 becomes +44 and a leading 00 becomes +. A number
     * already starting with + keeps its country code. Anything that does not
     * end up as valid E.164 returns '' so the caller skips it.
     */
    public static function toE164(string $phone): string
    {
        $trimmed = \trim($phone);
        $hasPlus = \str_starts_with($trimmed, '+');
        $digits = \preg_replace('/\D/', '', $trimmed) ?? '';
        if ($digits === '') return '';

        if ($hasPlus) $candidate = '+' . $digits;
        elseif (\str_starts_with($digits, '00')) $candidate = '+' . \substr($digits, 2);
        elseif (\str_starts_with($digits, '0')) $candidate = '+44' . \substr($digits, 1);
        elseif (\str_starts_with($digits, '44')) $candidate = '+' . $digits; // Typed with the code but no +
        else return '';

        return TwilioConfig::isValidE164($candidate) ? $candidate : '';
    }

    /**
     * Posts one message to Twilio. Used by the hook and the settings page's
     * test button, which sends even while the service is switched off.
     *
     * @return string "ok: <message sid>" or "error: <reason>"
     */
    public static function deliver(object $config, string $to, string $body): string
    {
        $sender = (string) $config->sender;
        $fields = ['To' => $to, 'Body' => $body];
        $fields[TwilioConfig::isMessagingServiceSid($sender) ? 'MessagingServiceSid' : 'From'] = $sender;

        $curl = \curl_init(\sprintf(self::MESSAGES_URL, \rawurlencode((string) $config->accountSid)));
        \curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($fields),
            CURLOPT_USERPWD => $config->accountSid . ':' . $config->authToken,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = \curl_exec($curl);
        $status = (int) \curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($curl);

        if ($response === false) return "error: {$curlError}";

        $decoded = \json_decode((string) $response, associative: true) ?? [];
        if ($status === 201) return 'ok: ' . (string) ($decoded['sid'] ?? '');

        $code = (string) ($decoded['code'] ?? '');
        $message = (string) ($decoded['message'] ?? 'unknown');

        return "error: HTTP {$status}" . ($code !== '' ? " (Twilio {$code})" : '') . " {$message}";
    }

    public static function isConfigured(object $config): bool
    {
        return TwilioConfig::isValidAccountSid((string) ($config->accountSid ?? ''))
            && TwilioConfig::isValidAuthToken((string) ($config->authToken ?? ''))
            && TwilioConfig::isValidSender((string) ($config->sender ?? ''));
    }
}
