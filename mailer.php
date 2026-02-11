<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail($toEmail, $toName, $verifyLink) {

    $mailUser = getenv('MAIL_USER');
    $mailPass = getenv('MAIL_PASS');
    $mailFrom = getenv('MAIL_FROM') ?: $mailUser;
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailUser;
        $mail->Password   = $mailPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($mailFrom, 'eBantay');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your eBantay account';
        $mail->Body = "
            <h2>Email Verification</h2>
            <p>Hello Kabayan! <b>$toName</b>,</p>
            <p>Please verify your email by clicking below:</p>
            <a href='$verifyLink'>Verify Email</a>
            <p>Please take note that this is only the first step for verification.</p>
            <p>You still need to complete setting up your account once you logged in the system to fully access the feature of eBantay. Thank you.</p>
        ";

        $mail->AltBody = "Verify your eBantay account: $verifyLink";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}
