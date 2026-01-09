<?php
/**
 * SMTP Connection Diagnostic Script
 * Run from command line: php test_smtp.php
 * DELETE THIS FILE AFTER DEBUGGING
 */

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// Load config
$config = parse_ini_file(__DIR__ . '/config/config.ini', true);

echo "=== SMTP Connection Test ===\n\n";
echo "Host: {$config['mail']['smtp_host']}\n";
echo "Port: {$config['mail']['smtp_port']}\n";
echo "User: {$config['mail']['smtp_user']}\n";
echo "From: {$config['mail']['from_email']}\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "OpenSSL Version: " . OPENSSL_VERSION_TEXT . "\n\n";

// Test different configurations
$tests = [
    [
        'name' => 'STARTTLS with SECLEVEL=0 (SHA-1 fix)',
        'port' => 587,
        'secure' => PHPMailer::ENCRYPTION_STARTTLS,
        'options' => [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'ciphers' => 'DEFAULT@SECLEVEL=0',
            ],
        ],
    ],
    [
        'name' => 'STARTTLS with TLS 1.2 forced',
        'port' => 587,
        'secure' => PHPMailer::ENCRYPTION_STARTTLS,
        'options' => [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ],
        ],
    ],
    [
        'name' => 'SMTPS (SSL on port 465)',
        'port' => 465,
        'secure' => PHPMailer::ENCRYPTION_SMTPS,
        'options' => [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ],
    ],
    [
        'name' => 'No encryption (port 25)',
        'port' => 25,
        'secure' => '',
        'options' => [],
    ],
];

foreach ($tests as $test) {
    echo "=== Testing: {$test['name']} (port {$test['port']}) ===\n";
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) {
            $str = trim($str);
            if ($str) echo "  $str\n";
        };
        
        $mail->isSMTP();
        $mail->Host = $config['mail']['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['mail']['smtp_user'];
        $mail->Password = $config['mail']['smtp_pass'];
        $mail->SMTPSecure = $test['secure'];
        $mail->Port = $test['port'];
        $mail->Timeout = 15;
        
        if (!empty($test['options'])) {
            $mail->SMTPOptions = $test['options'];
        }
        
        $mail->setFrom($config['mail']['from_email'], $config['mail']['from_name']);
        $mail->addAddress($config['mail']['from_email']);
        
        $mail->isHTML(false);
        $mail->Subject = 'SMTP Test - ' . date('Y-m-d H:i:s');
        $mail->Body = 'This is a test email to verify SMTP connectivity.';
        
        if ($mail->send()) {
            echo "\n  ✓ SUCCESS: Email sent!\n\n";
            break; // Stop on first success
        }
    } catch (Exception $e) {
        echo "\n  ✗ FAILED: {$mail->ErrorInfo}\n\n";
    }
}

echo "=== Tests complete ===\n";
