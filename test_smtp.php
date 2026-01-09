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
echo "From: {$config['mail']['from_email']}\n\n";

$mail = new PHPMailer(true);

try {
    // Enable verbose debug output
    $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
    $mail->Debugoutput = function($str, $level) {
        echo "DEBUG[$level]: $str\n";
    };
    
    $mail->isSMTP();
    $mail->Host = $config['mail']['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['mail']['smtp_user'];
    $mail->Password = $config['mail']['smtp_pass'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $config['mail']['smtp_port'];
    $mail->Timeout = 30; // Increase timeout
    
    // SSL options
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];
    
    $mail->setFrom($config['mail']['from_email'], $config['mail']['from_name']);
    $mail->addAddress($config['mail']['from_email']); // Send test to self
    
    $mail->isHTML(false);
    $mail->Subject = 'SMTP Test - ' . date('Y-m-d H:i:s');
    $mail->Body = 'This is a test email to verify SMTP connectivity.';
    
    echo "\n=== Attempting to send... ===\n\n";
    
    if ($mail->send()) {
        echo "\n✓ SUCCESS: Email sent!\n";
    }
} catch (Exception $e) {
    echo "\n✗ FAILED: {$mail->ErrorInfo}\n";
    echo "Exception: {$e->getMessage()}\n";
}
