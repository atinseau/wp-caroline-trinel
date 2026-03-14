<?php
/**
 * Plugin Name:  SMTP Mailer
 * Description:  Routes WordPress emails through an SMTP server when SMTP_HOST is defined. Designed for use with Mailpit in development.
 * Author:       Development Team
 * License:      MIT
 */

if (! getenv('SMTP_HOST')) {
    return;
}

add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = getenv('SMTP_HOST');
    $phpmailer->Port = getenv('SMTP_PORT') ?: 1025;
    $phpmailer->SMTPAuth = false;
    $phpmailer->SMTPAutoTLS = false;
    $phpmailer->SMTPSecure = '';
});
