<?php

declare(strict_types=1);

namespace Smilo\Helper;

/**
 * Minimal dependency-free mailer (keeps the Flight "zero dependencies" ethos).
 *
 * - SMTP_HOST set  → sends over SMTP (implicit SSL on 465, STARTTLS otherwise,
 *                    AUTH LOGIN when SMTP_USER is set).
 * - SMTP_HOST blank → appends the message to storage/logs/mail.log, so local
 *                    dev can exercise the reset flow without mail credentials.
 *
 * send() never throws: callers get a bool and decide what to tell the user.
 */
class Mailer
{
    public static function isConfigured(): bool
    {
        return (string) (getenv('SMTP_HOST') ?: '') !== '';
    }

    public static function send(string $to, string $subject, string $html): bool
    {
        $from     = (string) (getenv('MAIL_FROM') ?: 'no-reply@smilo.ng');
        $fromName = (string) (getenv('MAIL_FROM_NAME') ?: 'Smilo Marketplace');

        if (!self::isConfigured()) {
            return self::log($to, $subject, $html, $from);
        }

        try {
            return self::smtp($to, $subject, $html, $from, $fromName);
        } catch (\Throwable $e) {
            error_log('[mailer] SMTP send failed: ' . $e->getMessage());
            self::log($to, $subject, $html, $from, $e->getMessage());
            return false;
        }
    }

    private static function smtp(string $to, string $subject, string $html, string $from, string $fromName): bool
    {
        $host   = (string) getenv('SMTP_HOST');
        $port   = (int) (getenv('SMTP_PORT') ?: 587);
        $user   = (string) (getenv('SMTP_USER') ?: '');
        $pass   = (string) (getenv('SMTP_PASS') ?: '');
        $secure = strtolower((string) (getenv('SMTP_SECURE') ?: ($port === 465 ? 'ssl' : 'tls')));
        $verify = filter_var(getenv('SMTP_VERIFY_PEER') ?: 'true', FILTER_VALIDATE_BOOLEAN);
        $ehlo   = gethostname() ?: 'localhost';

        $context = stream_context_create(['ssl' => [
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
        ]]);

        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client(
            "{$transport}{$host}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($fp === false) {
            throw new \RuntimeException("connect to {$host}:{$port} failed — {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 15);

        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                // Last line of a multiline reply has '-' replaced by ' '
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };

        $expect = static function (string $command, array $codes) use ($fp, $read): string {
            if ($command !== '') {
                fwrite($fp, $command . "\r\n");
            }
            $reply = $read();
            $code  = (int) substr($reply, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new \RuntimeException("SMTP `{$command}` → " . trim($reply));
            }
            return $reply;
        };

        $expect('', [220]);
        $expect('EHLO ' . $ehlo, [250]);

        if ($secure === 'tls') {
            $expect('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed');
            }
            $expect('EHLO ' . $ehlo, [250]);
        }

        if ($user !== '') {
            $expect('AUTH LOGIN', [334]);
            $expect(base64_encode($user), [334]);
            $expect(base64_encode($pass), [235]);
        }

        $expect("MAIL FROM:<{$from}>", [250]);
        $expect("RCPT TO:<{$to}>", [250, 251]);
        $expect('DATA', [354]);

        fwrite($fp, self::message($to, $subject, $html, $from, $fromName) . "\r\n.\r\n");
        $reply = $read();
        if ((int) substr($reply, 0, 3) !== 250) {
            throw new \RuntimeException('SMTP body rejected: ' . trim($reply));
        }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        return true;
    }

    private static function message(string $to, string $subject, string $html, string $from, string $fromName): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . " <{$from}>",
            "To: <{$to}>",
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
    }

    /** RFC 2047 encode only when the header has non-ASCII (e.g. emoji, ₦). */
    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private static function log(string $to, string $subject, string $html, string $from, ?string $error = null): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = str_repeat('=', 72) . "\n"
            . date('c') . "\n"
            . "To: {$to}\nFrom: {$from}\n"
            . ($error !== null ? "Send error: {$error}\n" : '')
            . "Subject: {$subject}\n\n{$html}\n";

        return (bool) @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);
    }
}
