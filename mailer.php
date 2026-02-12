<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail($toEmail, $toName, $verifyLink) {

    $mailUser = getenv('MAIL_USER');
    $mailPass = getenv('MAIL_PASS');
    $mailFrom = getenv('MAIL_FROM') ?: $mailUser;

    // Ensure env variables exist
    if (!$mailUser || !$mailPass) {
        error_log("MAIL_USER or MAIL_PASS not set in environment.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailUser;
        $mail->Password   = $mailPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0; // Keep 0 in production

        $mail->setFrom($mailFrom, 'eBantay');
        $mail->addAddress($toEmail, $toName);

        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'Verify your eBantay account';
        $mail->Body = "
            <h2>Email Verification</h2>
            <p>Hello Kabayan! <b>{$safeName}</b>,</p>
            <p>Please verify your email by clicking below:</p>
            <p><a href='{$verifyLink}' style='color:#1D4ED8;font-weight:bold;'>Verify Email</a></p>
            <p>Please take note that this is only the first step for verification.</p>
            <p>You still need to complete setting up your account once you log in to fully access the features of eBantay.</p>
            <p>Thank you.</p>
        ";

        $mail->AltBody = "Verify your eBantay account: {$verifyLink}";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}
