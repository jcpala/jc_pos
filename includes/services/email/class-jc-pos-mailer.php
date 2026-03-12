<?php
/**********************************************************************************************
 * Class Objectives:
1. prepare headers
2. support HTML email
3. validate recipient
4. validate attachments
5. call wp_mail()
6. capture WP mail failure message
7. return a normalized response
 *************************************************************************************************/

if (!defined('ABSPATH')) exit;

class JC_POS_Mailer {

    /**
     * Send one HTML email with optional attachments.
     *
     * Expected $args:
     * [
     *   'to'          => 'customer@example.com',
     *   'subject'     => 'Your invoice',
     *   'message'     => '<p>Hello</p>',
     *   'attachments' => ['/absolute/path/file.pdf'],
     *   'headers'     => [],
     *   'from_email'  => '',
     *   'from_name'   => '',
     * ]
     */
    public function send(array $args): array {
        $to          = isset($args['to']) ? sanitize_email((string) $args['to']) : '';
        $subject     = isset($args['subject']) ? wp_strip_all_tags((string) $args['subject']) : '';
        $message     = isset($args['message']) ? (string) $args['message'] : '';
        $attachments = isset($args['attachments']) && is_array($args['attachments']) ? $args['attachments'] : [];
        $headers     = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $from_email  = isset($args['from_email']) ? sanitize_email((string) $args['from_email']) : '';
        $from_name   = isset($args['from_name']) ? sanitize_text_field((string) $args['from_name']) : '';

        if (empty($to) || !is_email($to)) {
            return $this->error_result('Invalid recipient email.');
        }

        if ($subject === '') {
            return $this->error_result('Email subject is required.');
        }

        if ($message === '') {
            return $this->error_result('Email message is required.');
        }

        $attachments = $this->filter_valid_attachments($attachments);

        $headers = $this->build_headers($headers, $from_email, $from_name);

        $wp_error_message = '';

        $failure_handler = function ($wp_error) use (&$wp_error_message) {
            if (is_wp_error($wp_error)) {
                $wp_error_message = $wp_error->get_error_message();
            } else {
                $wp_error_message = 'Unknown wp_mail failure.';
            }
        };

        add_action('wp_mail_failed', $failure_handler, 10, 1);

        try {
            $sent = wp_mail($to, $subject, $message, $headers, $attachments);
        } finally {
            remove_action('wp_mail_failed', $failure_handler, 10);
        }

        if (!$sent) {
            return $this->error_result(
                $wp_error_message !== '' ? $wp_error_message : 'wp_mail returned false.',
                [
                    'to'          => $to,
                    'subject'     => $subject,
                    'attachments' => $attachments,
                ]
            );
        }

        return [
            'ok'          => true,
            'message'     => 'Email sent successfully.',
            'to'          => $to,
            'subject'     => $subject,
            'attachments' => $attachments,
        ];
    }

    /**
     * Build email headers for HTML mail.
     */
    protected function build_headers(array $headers = [], string $from_email = '', string $from_name = ''): array {
        $final_headers = [];

        $final_headers[] = 'Content-Type: text/html; charset=UTF-8';

        if ($from_email !== '' && is_email($from_email)) {
            if ($from_name !== '') {
                $final_headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
            } else {
                $final_headers[] = sprintf('From: <%s>', $from_email);
            }
        }

        foreach ($headers as $header) {
            if (!is_string($header) || trim($header) === '') {
                continue;
            }
            $final_headers[] = trim($header);
        }

        return array_values(array_unique($final_headers));
    }

    /**
     * Keep only valid attachment paths that exist.
     */
    protected function filter_valid_attachments(array $attachments): array {
        $valid = [];

        foreach ($attachments as $file) {
            if (!is_string($file)) {
                continue;
            }

            $file = trim($file);

            if ($file === '') {
                continue;
            }

            if (!file_exists($file)) {
                continue;
            }

            if (!is_readable($file)) {
                continue;
            }

            $valid[] = $file;
        }

        return array_values(array_unique($valid));
    }

    /**
     * Standard error response.
     */
    protected function error_result(string $message, array $extra = []): array {
        return array_merge([
            'ok'      => false,
            'message' => $message,
        ], $extra);
    }
}