<?php

namespace App\Services;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class UtilityService
{
    public function __construct(private readonly ConfigService $config)
    {
    }

    /**
     * Send email using SMTP.
     *
     * @param string|string[] $to Single address or list of addresses to deliver to.
     *                            All listed addresses appear on the To: header
     *                            and are sent in a single SMTP transaction.
     */
    public function sendEmail(string|array $to, string $subject, string $body, bool $isHtml = true): bool
    {
        $recipients = $this->normaliseRecipients($to);
        if (empty($recipients)) {
            LogService::warning('sendEmail called with no usable recipients', [
                'subject' => $subject,
            ]);
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $this->config::get('mail.smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = $this->config::get('mail.smtp_user');
            $mail->Password = $this->config::get('mail.smtp_pass');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->config::get('mail.smtp_port');

            // Allow self-signed certificates and SHA-1 signatures (legacy Exchange server)
            // The Exchange server uses RSA-SHA1 which OpenSSL 3.0 rejects at default SECLEVEL=2
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'ciphers' => 'DEFAULT@SECLEVEL=0',
                ],
            ];

            $mail->setFrom(
                $this->config::get('mail.from_email'),
                $this->config::get('mail.from_name')
            );
            foreach ($recipients as $address) {
                $mail->addAddress($address);
            }

            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (Exception $e) {
            LogService::error('Failed to send email (PHPMailer)', [
                'to' => $recipients,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'smtp_host' => $this->config::get('mail.smtp_host'),
                'smtp_port' => $this->config::get('mail.smtp_port'),
            ]);
            return false;
        } catch (\Throwable $e) {
            LogService::error('Failed to send email (General)', [
                'to' => $recipients,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'exception_type' => get_class($e),
                'smtp_host' => $this->config::get('mail.smtp_host'),
                'smtp_port' => $this->config::get('mail.smtp_port'),
            ]);
            return false;
        }
    }

    /**
     * @param string|string[] $to
     * @return string[] de-duplicated, trimmed, non-empty addresses
     */
    private function normaliseRecipients(string|array $to): array
    {
        $raw = is_array($to) ? $to : [$to];
        $out = [];
        foreach ($raw as $address) {
            $address = trim((string) $address);
            if ($address !== '') {
                $out[] = $address;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Get base URL of the application.
     */
    public function getBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');

        $baseDir = str_replace('/public', '', $baseDir);

        return rtrim($protocol . $host . $baseDir, '/') . '/';
    }
}

