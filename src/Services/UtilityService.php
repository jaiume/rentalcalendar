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
     */
    public function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $this->config::get('mail.smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = $this->config::get('mail.smtp_user');
            $mail->Password = $this->config::get('mail.smtp_pass');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->config::get('mail.smtp_port');

            // Allow self-signed certificates
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom(
                $this->config::get('mail.from_email'),
                $this->config::get('mail.from_name')
            );
            $mail->addAddress($to);

            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (Exception $e) {
            // @todo integrate with logger
            return false;
        }
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

