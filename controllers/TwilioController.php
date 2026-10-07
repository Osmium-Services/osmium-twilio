<?php

declare(strict_types=1);

namespace Osmium\Services\Twilio\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Twilio\Models\TwilioConfig;
use Osmium\Services\Twilio\Models\TwilioSms;

/**
 * Twilio SMS settings controller - a full-page form POST/redirect flow,
 * matching the Meta Pixel service.
 *
 * Routes:
 *   - index() → /admin/settings/twilio/  (GET shows the form, POST saves it
 *     or, with action=send_test, sends a test text with the saved settings)
 */
class TwilioController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/twilio.json.php';
    private const DEFAULT_CONFIG = <<<'JSON'
        <?php exit(); ?>
        {
            "twilio": {
                "enabled": false,
                "accountSid": "",
                "authToken": "",
                "sender": ""
            }
        }
        JSON;

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $config = (array) TwilioConfig::get();
        $config['authTokenSet'] = ($config['authToken'] ?? '') !== '';
        unset($config['authToken']); // The saved token never reaches the page
        $this->data['admin']['config']['twilio'] = $config;
        $this->data['admin']['settingsSaved'] = $_SESSION['twilio_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['twilio_settings_error'] ?? false;
        $this->data['admin']['testResult'] = $_SESSION['twilio_test_result'] ?? false;
        unset($_SESSION['twilio_settings_saved'], $_SESSION['twilio_settings_error'], $_SESSION['twilio_test_result']);

        $this->setView('twilio/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['twilio_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/twilio/');
        }

        $isTest = ($_POST['action'] ?? '') === 'send_test';
        if ($isTest) $this->handleTest();

        $enabled = isset($_POST['enabled']);
        $accountSid = \trim($_POST['account_sid'] ?? '');
        $sender = \trim($_POST['sender'] ?? '');

        $postedToken = \trim($_POST['auth_token'] ?? '');
        $authToken = $postedToken === '' ? (string) (TwilioConfig::get()->authToken ?? '') : $postedToken; // Blank keeps the stored secret

        $error = $this->validate(
            enabled: $enabled,
            accountSid: $accountSid,
            authToken: $authToken,
            sender: $sender,
        );
        if ($error !== null) {
            $_SESSION['twilio_settings_error'] = $error;
            $this->redirect('settings/twilio/');
        }

        $this->saveConfig(
            enabled: $enabled,
            accountSid: $accountSid,
            authToken: $authToken,
            sender: $sender,
        );

        $this->admin->model->changelog->log(
            description: 'Updated Twilio SMS settings',
            recordType: 'settings',
        );

        TwilioConfig::clearCache();

        $_SESSION['twilio_settings_saved'] = true;
        $this->redirect('settings/twilio/');
    }

    private function validate(bool $enabled, string $accountSid, string $authToken, string $sender): ?string
    {
        $hasAnyCredential = $accountSid !== '' || $authToken !== '' || $sender !== '';
        $needsValidCredentials = $enabled || $hasAnyCredential;

        if ($needsValidCredentials && !TwilioConfig::isValidAccountSid($accountSid)) {
            return 'Account SID is "AC" followed by 32 letters/digits (from the Twilio Console).';
        }
        if ($needsValidCredentials && !TwilioConfig::isValidAuthToken($authToken)) {
            return 'Auth Token is 32 letters/digits (from the Twilio Console). Paste only the token.';
        }
        if ($needsValidCredentials && !TwilioConfig::isValidSender($sender)) {
            return 'Sender must be a number like +447700900123, an alphanumeric sender ID (up to 11 characters, at least one letter), or a Messaging Service SID starting MG.';
        }

        return null;
    }

    /** Sends a test text using the saved settings, so what is tested is what is live. */
    private function handleTest(): void
    {
        $config = TwilioConfig::get();
        $to = TwilioSms::toE164(\trim($_POST['test_number'] ?? ''));

        if (!TwilioSms::isConfigured($config)) {
            $_SESSION['twilio_settings_error'] = 'Save valid credentials and a sender first, then send a test.';
        } elseif ($to === '') {
            $_SESSION['twilio_settings_error'] = 'Enter a mobile number to text, such as 07700 900123 or +447700900123.';
        } else {
            $_SESSION['twilio_test_result'] = TwilioSms::deliver(config: $config, to: $to, body: 'This is a test text from your Osmium site.');
        }

        $this->redirect('settings/twilio/');
    }

    private function saveConfig(
        bool $enabled,
        string $accountSid,
        string $authToken,
        string $sender,
    ): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = $configExists ? \file_get_contents(self::CONFIG_FILE_PATH) : self::DEFAULT_CONFIG;

        $jsonStart = \strpos(haystack: $content, needle: '{');
        $phpHeader = \substr(string: $content, offset: 0, length: $jsonStart);
        $data = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];

        $data['twilio'] = [
            'enabled' => $enabled,
            'accountSid' => $accountSid,
            'authToken' => $authToken,
            'sender' => $sender,
        ];

        $newJson = \json_encode(
            value: $data,
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, $phpHeader . $newJson . "\n");
    }

    private function ensureConfigDirectoryExists(): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $alreadyExists = \is_dir($dir);
        if (!$alreadyExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
