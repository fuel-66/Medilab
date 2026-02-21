<?php
// mailer.php - PHPMailer wrapper
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function send_email($to, $subject, $body_html, $body_text = '') {
    $mail = new PHPMailer(true);
    try {
        // SMTP config - update with your SMTP provider details
        $mail->isSMTP();
        $mail->Host       = 'smtp.example.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'smtp_user@example.com';
        $mail->Password   = 'smtp_password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('no-reply@yourdomain.com', 'VaxCare Pro');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = $body_text ?: strip_tags($body_html);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // log $mail->ErrorInfo in production
        return false;
    }
}
