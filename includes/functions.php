<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

function sendMail($toEmail, $toName, $subject, $htmlMessage, $qrImagePath = null) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();


    $mail->CharSet = 'UTF-8';
    $mail->Host       = getenv('SMTP_HOST') ?: 'localhost';
    $mail->SMTPDebug  = 0;                     // enables SMTP debug information (for testing)
    $mail->SMTPAuth   = (bool) getenv('SMTP_USERNAME');
    $mail->Username   = getenv('SMTP_USERNAME') ?: '';
    $mail->Password   = getenv('SMTP_PASSWORD') ?: '';
    $mail->Port       = (int) (getenv('SMTP_PORT') ?: 2525);
    $mail->isHTML(true);                       // Set email format to HTML

        $mail->setFrom(
            getenv('SMTP_FROM_ADDRESS') ?: 'no-reply@example.com',
            getenv('SMTP_FROM_NAME') ?: 'Coffee Multivendor'
        );
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;

        if ($qrImagePath && file_exists($qrImagePath)) {
            $mail->AddEmbeddedImage($qrImagePath, 'qr');
            $htmlMessage .= "<br><img src='cid:qr' alt='Scan to login'>";
        }

        $mail->Body = $htmlMessage;
        $mail->AltBody = strip_tags($htmlMessage);

        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
