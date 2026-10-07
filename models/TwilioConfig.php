<?php

declare(strict_types=1);

namespace Osmium\Services\Twilio\Models;

/**
 * Twilio SMS configuration helper.
 *
 * Loads this service's own settings file, matching the file-based config
 * convention used by the other services (app/config/services/{id}.json.php).
 */
class TwilioConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/twilio.json.php';

    /**
     * Falls back to defaults (disabled, no credentials) if the config file is
     * missing, so installing this service stays inert until someone visits
     * its settings page and saves credentials.
     */
    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = (string) \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));

        self::$config = (object) ((array) ($decoded->twilio ?? []) + (array) self::defaults());

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /** An Account SID is "AC" plus 32 hex characters. */
    public static function isValidAccountSid(string $sid): bool
    {
        return \preg_match(pattern: '/^AC[0-9a-f]{32}$/i', subject: $sid) === 1;
    }

    /** An Auth Token is 32 hex characters. */
    public static function isValidAuthToken(string $token): bool
    {
        return \preg_match(pattern: '/^[0-9a-f]{32}$/i', subject: $token) === 1;
    }

    /**
     * Sender is one of: an E.164 number, an alphanumeric sender ID (1-11
     * letters/digits/spaces with at least one letter), or a Messaging
     * Service SID ("MG" plus 32 hex characters).
     */
    public static function isValidSender(string $sender): bool
    {
        return self::isMessagingServiceSid($sender)
            || self::isValidE164($sender)
            || \preg_match(pattern: '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', subject: $sender) === 1;
    }

    public static function isMessagingServiceSid(string $sender): bool
    {
        return \preg_match(pattern: '/^MG[0-9a-f]{32}$/i', subject: $sender) === 1;
    }

    public static function isValidE164(string $number): bool
    {
        return \preg_match(pattern: '/^\+[1-9]\d{6,14}$/', subject: $number) === 1;
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'accountSid' => '',
            'authToken' => '',
            'sender' => '',
        ];
    }
}
