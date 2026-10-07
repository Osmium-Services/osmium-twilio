<?php

declare(strict_types=1);

namespace Osmium\Services\Twilio\Models;

/**
 * Texts the customer when an order is paid.
 *
 * Driven by core's order.paid hook, which fires once per order whichever
 * route paid it (the visitor's browser or a provider webhook), so no
 * "already sent" record is needed. Best-effort: core logs and skips a handler
 * that throws, and a Twilio failure is written to the error log and never
 * blocks the sale.
 *
 * Order texts are production-only so dev and staging never text a real
 * customer. The settings page's test button works in any environment.
 */
class TwilioSms
{
    private const MESSAGES_URL = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    public static function orderPaid(array $payload): void
    {
        $config = TwilioConfig::get();
        if (!self::orderTextsActive($config)) return;

        $order = $payload['order'];
        $to = self::toE164((string) ($order['customer_phone'] ?? ''));
        if ($to === '') return; // No usable number: nothing to text

        $result = self::send(config: $config, to: $to, body: self::render(template: (string) $config->template, order: $order));
        $isFailure = !\str_starts_with($result, 'ok');
        if ($isFailure) \error_log("Twilio SMS for order {$order['order_ref']}: {$result}");
    }

    /**
     * Fills {name}, {order_ref} and {total} in the template. Name is the
     * customer's first name only, to keep the text short and friendly.
     *
     * @param array<string, mixed> $order
     */
    public static function render(string $template, array $order): string
    {
        $parts = \preg_split('/\s+/', \trim((string) ($order['customer_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $parts[0] ?? 'there';

        $currency = (string) ($order['currency'] ?? 'GBP');
        $symbol = $currency === 'GBP' ? '£' : $currency . ' ';
        $total = $symbol . \number_format((float) ($order['total_inc_tax'] ?? 0), 2);

        return \strtr($template, [
            '{name}' => $firstName,
            '{order_ref}' => (string) ($order['order_ref'] ?? ''),
            '{total}' => $total,
        ]);
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
     * Sends one text. Used by the order hook and the settings page's test
     * button. Takes the config so the test button can use unsaved values.
     *
     * @return string "ok: <message sid>" or "error: <reason>"
     */
    public static function send(object $config, string $to, string $body): string
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

    private static function orderTextsActive(object $config): bool
    {
        $isProduction = !\in_array(TwilioConfig::siteEnvironment(), ['dev', 'staging', ''], true);

        return ($config->enabled ?? false) && self::isConfigured($config) && $isProduction;
    }
}
