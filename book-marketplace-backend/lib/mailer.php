<?php
declare(strict_types=1);

/**
 * Transactional email via Resend HTTP API.
 * Uses cURL to avoid SMTP port blocking on serverless/cloud platforms.
 *
 * Dev mode (RESEND_API_KEY is empty):
 *   • Logs the full email text to /tmp/librowse_mail.log
 *   • Returns dev_preview_link in the response so you can click through
 *     the verification/reset flow without a real email account.
 */

// ── URL resolution ─────────────────────────────────────────────────────────

function resolve_app_url(): string
{
    $envUrl = getenv('APP_URL');
    if ($envUrl !== false && trim($envUrl) !== '') {
        return rtrim(trim($envUrl), '/');
    }

    foreach (['VERCEL_PROJECT_PRODUCTION_URL', 'VERCEL_URL'] as $k) {
        $v = getenv($k);
        if ($v !== false && trim($v) !== '') {
            $v = trim($v);
            return str_starts_with($v, 'http') ? rtrim($v, '/') : 'https://' . rtrim($v, '/');
        }
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && preg_match('#^https?://[^/]+$#i', $origin)) {
        return rtrim($origin, '/');
    }

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
             (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
    return "{$proto}://{$host}";
}

// ── Core send function ─────────────────────────────────────────────────────

function send_resend_email(string $to, string $subject, string $html, string $text): array
{
    $apiKey = trim((string) (getenv('RESEND_API_KEY') ?: ''));
    $from   = trim((string) (getenv('MAIL_FROM') ?: 'Librowse Book Exchange <onboarding@resend.dev>'));

    // ── Dev mode fallback ──────────────────────────────────────────────────
    if ($apiKey === '') {
        $logEntry = sprintf(
            "[%s] [DEV MAILER]\nTo: %s\nSubject: %s\n\n%s\n%s\n",
            gmdate('Y-m-d H:i:s'),
            $to,
            $subject,
            $text,
            str_repeat('-', 60)
        );
        error_log('[librowse-mailer] ' . $subject . ' -> ' . $to);
        $logFile = sys_get_temp_dir() . '/librowse_mail.log';
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        return [
            'success'       => true,
            'dev_mode'      => true,
            'recipient'     => $to,
            'log_file'      => $logFile,
        ];
    }

    // ── Resend HTTP API ────────────────────────────────────────────────────
    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['success' => false, 'error' => 'cURL error: ' . $curlErr];
    }

    $resp = is_string($body) ? json_decode($body, true) : null;
    if ($status >= 200 && $status < 300) {
        return ['success' => true, 'id' => $resp['id'] ?? null];
    }

    return [
        'success' => false,
        'error'   => ($resp['message'] ?? 'Resend API error') . " (HTTP {$status})",
    ];
}

// ── Email templates ────────────────────────────────────────────────────────

/**
 * Sends an email-verification link to a newly registered user.
 * The raw token is stored on disk only briefly and never in the DB.
 * Inserts or replaces the EMAIL_VERIFICATIONS row.
 *
 * @return array{success:bool, dev_mode?:bool, dev_preview_link?:string, log_file?:string, error?:string}
 */
function send_verification_email(string $toEmail, string $username, string $rawToken): array
{
    $base    = resolve_app_url();
    $link    = "{$base}/book-marketplace-frontend/verify-email.html?token=" . urlencode($rawToken);
    $appName = 'Librowse Book Exchange';

    $subject = 'Confirm your email — ' . $appName;

    $text = <<<TXT
Hi {$username},

Thanks for joining {$appName}! Click the link below to verify your email address.
The link expires in 24 hours.

{$link}

If you did not create an account, you can safely ignore this email.

— The {$appName} team
TXT;

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family:sans-serif;background:#f9f6ef;margin:0;padding:32px">
  <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
    <h1 style="color:#5a3e2b;font-size:22px;margin-top:0">Verify your email address</h1>
    <p style="color:#444">Hi <strong>{$username}</strong>,</p>
    <p style="color:#444">Thanks for joining <strong>{$appName}</strong>! Please confirm your email address by clicking the button below. The link expires in <strong>24 hours</strong>.</p>
    <p style="text-align:center;margin:32px 0">
      <a href="{$link}" style="background:#8b5e3c;color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:bold;display:inline-block">Verify my email</a>
    </p>
    <p style="color:#888;font-size:13px">Or copy and paste this URL into your browser:<br>{$link}</p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
    <p style="color:#aaa;font-size:12px;margin:0">If you did not create an account, you can safely ignore this email.</p>
  </div>
</body>
</html>
HTML;

    $result = send_resend_email($toEmail, $subject, $html, $text);

    if ($result['dev_mode'] ?? false) {
        $result['dev_preview_link'] = $link;
    }
    return $result;
}

/**
 * Sends a password-reset link.
 *
 * @return array{success:bool, dev_mode?:bool, dev_preview_link?:string, log_file?:string, error?:string}
 */
function send_password_reset_email(string $toEmail, string $username, string $rawToken): array
{
    $base    = resolve_app_url();
    $link    = "{$base}/book-marketplace-frontend/reset-password.html?token=" . urlencode($rawToken);
    $appName = 'Librowse Book Exchange';

    $subject = 'Reset your password — ' . $appName;

    $text = <<<TXT
Hi {$username},

We received a request to reset the password for your {$appName} account.
Click the link below to choose a new password. The link expires in 1 hour and can only be used once.

{$link}

If you did not request a password reset, you can safely ignore this email.
Your password will not change.

— The {$appName} team
TXT;

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family:sans-serif;background:#f9f6ef;margin:0;padding:32px">
  <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
    <h1 style="color:#5a3e2b;font-size:22px;margin-top:0">Reset your password</h1>
    <p style="color:#444">Hi <strong>{$username}</strong>,</p>
    <p style="color:#444">We received a request to reset your <strong>{$appName}</strong> password. Click the button below. The link expires in <strong>1 hour</strong> and can only be used once.</p>
    <p style="text-align:center;margin:32px 0">
      <a href="{$link}" style="background:#8b5e3c;color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:bold;display:inline-block">Reset my password</a>
    </p>
    <p style="color:#888;font-size:13px">Or copy and paste this URL into your browser:<br>{$link}</p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
    <p style="color:#aaa;font-size:12px;margin:0">If you did not request a password reset, you can safely ignore this email. Your password will not change.</p>
  </div>
</body>
</html>
HTML;

    $result = send_resend_email($toEmail, $subject, $html, $text);

    if ($result['dev_mode'] ?? false) {
        $result['dev_preview_link'] = $link;
    }
    return $result;
}
