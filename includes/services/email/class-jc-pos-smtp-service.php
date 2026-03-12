<?php
if (!defined('ABSPATH')) exit;

class JC_POS_SMTP_Service
{
    public static function init(): void
    {
        add_action('phpmailer_init', [__CLASS__, 'configure_phpmailer']);
        add_filter('wp_mail_from', [__CLASS__, 'filter_wp_mail_from']);
        add_filter('wp_mail_from_name', [__CLASS__, 'filter_wp_mail_from_name']);
    }

    public static function filter_wp_mail_from($from_email): string
    {
        if (defined('JC_POS_SMTP_FROM_EMAIL') && JC_POS_SMTP_FROM_EMAIL !== '') {
            return JC_POS_SMTP_FROM_EMAIL;
        }

        return $from_email;
    }

    public static function filter_wp_mail_from_name($from_name): string
    {
        if (defined('JC_POS_SMTP_FROM_NAME') && JC_POS_SMTP_FROM_NAME !== '') {
            return JC_POS_SMTP_FROM_NAME;
        }

        return $from_name;
    }

    public static function configure_phpmailer($phpmailer): void
    {
        if (
            !defined('JC_POS_SMTP_HOST') ||
            !defined('JC_POS_SMTP_PORT') ||
            !defined('JC_POS_SMTP_USER') ||
            !defined('JC_POS_SMTP_PASS') ||
            !defined('JC_POS_SMTP_FROM_EMAIL')
        ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = JC_POS_SMTP_HOST;
        $phpmailer->Port       = (int) JC_POS_SMTP_PORT;
        $phpmailer->SMTPAuth   = defined('JC_POS_SMTP_AUTH') ? (bool) JC_POS_SMTP_AUTH : true;
        $phpmailer->Username   = JC_POS_SMTP_USER;
        $phpmailer->Password   = JC_POS_SMTP_PASS;
        $phpmailer->SMTPSecure = defined('JC_POS_SMTP_SECURE') ? JC_POS_SMTP_SECURE : 'ssl';
        $phpmailer->CharSet    = 'UTF-8';

        $from_email = JC_POS_SMTP_FROM_EMAIL;
        $from_name  = defined('JC_POS_SMTP_FROM_NAME') ? JC_POS_SMTP_FROM_NAME : get_bloginfo('name');

        $phpmailer->From     = $from_email;
        $phpmailer->FromName = $from_name;
        $phpmailer->Sender   = $from_email;

        try {
            $phpmailer->setFrom($from_email, $from_name, false);
        } catch (Exception $e) {
            // Let wp_mail_failed surface the error.
        }
    }
}