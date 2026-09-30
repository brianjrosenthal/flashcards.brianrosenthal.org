<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/EmailLog.php';

/**
 * Send an HTML email via SMTP (supports SSL 465 and STARTTLS 587), reporting
 * the failure reason through $errorMessage. Returns true on success.
 */
function send_email_with_error(string $toEmail, string $subject, string $html, string $toName = '', string &$errorMessage = ''): bool {
  if ($toName === '') $toName = $toEmail;

  if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
    $errorMessage = 'SMTP configuration missing (SMTP_HOST, SMTP_PORT, SMTP_USER, or SMTP_PASS not defined)';
    return false;
  }

  $host = SMTP_HOST;
  $port = (int)SMTP_PORT;
  $user = SMTP_USER;
  $pass = SMTP_PASS;
  $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
  $fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : SMTP_USER;
  $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Flashcards';

  $timeout = 20;
  $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
  $fp = @stream_socket_client("$transport:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
  if (!$fp) {
    $errorMessage = "Failed to connect to SMTP server $host:$port - $errstr (errno: $errno)";
    return false;
  }

  stream_set_timeout($fp, $timeout);

  $lastResponse = '';
  $expect = function(array $codes) use ($fp, &$lastResponse): bool {
    $line = '';
    do {
      $line = fgets($fp, 515);
      if ($line === false) {
        $lastResponse = 'Connection lost or timeout';
        return false;
      }
      $lastResponse = trim($line);
      $code = (int)substr($line, 0, 3);
      $more = isset($line[3]) && $line[3] === '-';
    } while ($more);
    return in_array($code, $codes, true);
  };

  $send = function(string $cmd) use ($fp): bool {
    return fwrite($fp, $cmd . "\r\n") !== false;
  };

  if (!$expect([220])) {
    fclose($fp);
    $errorMessage = "SMTP greeting failed: $lastResponse";
    return false;
  }

  $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
  if (!$send("EHLO " . $ehloName)) { fclose($fp); $errorMessage = 'Failed to send EHLO command'; return false; }
  if (!$expect([250])) { fclose($fp); $errorMessage = "EHLO failed: $lastResponse"; return false; }

  if ($secure === 'tls') {
    if (!$send("STARTTLS")) { fclose($fp); $errorMessage = 'Failed to send STARTTLS command'; return false; }
    if (!$expect([220])) { fclose($fp); $errorMessage = "STARTTLS failed: $lastResponse"; return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($fp);
      $errorMessage = 'Failed to enable TLS encryption';
      return false;
    }
    if (!$send("EHLO " . $ehloName)) { fclose($fp); $errorMessage = 'Failed to send EHLO after STARTTLS'; return false; }
    if (!$expect([250])) { fclose($fp); $errorMessage = "EHLO after STARTTLS failed: $lastResponse"; return false; }
  }

  // AUTH LOGIN
  if (!$send("AUTH LOGIN")) { fclose($fp); $errorMessage = 'Failed to send AUTH LOGIN command'; return false; }
  if (!$expect([334])) { fclose($fp); $errorMessage = "AUTH LOGIN failed: $lastResponse"; return false; }
  if (!$send(base64_encode($user))) { fclose($fp); $errorMessage = 'Failed to send username'; return false; }
  if (!$expect([334])) { fclose($fp); $errorMessage = "Username authentication failed: $lastResponse"; return false; }
  if (!$send(base64_encode($pass))) { fclose($fp); $errorMessage = 'Failed to send password'; return false; }
  if (!$expect([235])) { fclose($fp); $errorMessage = "Password authentication failed: $lastResponse"; return false; }

  // Envelope
  if (!$send("MAIL FROM:<$fromEmail>")) { fclose($fp); $errorMessage = 'Failed to send MAIL FROM command'; return false; }
  if (!$expect([250])) { fclose($fp); $errorMessage = "MAIL FROM failed: $lastResponse"; return false; }
  if (!$send("RCPT TO:<$toEmail>")) { fclose($fp); $errorMessage = 'Failed to send RCPT TO command'; return false; }
  if (!$expect([250, 251])) { fclose($fp); $errorMessage = "RCPT TO failed: $lastResponse"; return false; }
  if (!$send("DATA")) { fclose($fp); $errorMessage = 'Failed to send DATA command'; return false; }
  if (!$expect([354])) { fclose($fp); $errorMessage = "DATA command failed: $lastResponse"; return false; }

  // Headers
  $date = date('r');
  $headers = [];
  $headers[] = "Date: $date";
  $headers[] = "From: " . mb_encode_mimeheader($fromName) . " <{$fromEmail}>";
  $headers[] = "To: " . mb_encode_mimeheader($toName) . " <{$toEmail}>";
  $headers[] = "Subject: " . mb_encode_mimeheader($subject);
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/html; charset=UTF-8";
  $headers[] = "Content-Transfer-Encoding: 8bit";

  // Normalize newlines and dot-stuffing
  $body = preg_replace("/\r\n|\r|\n/", "\r\n", $html);
  $body = preg_replace("/^\./m", "..", $body);

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
  if (!$send($data)) { fclose($fp); $errorMessage = 'Failed to send email data'; return false; }
  if (!$expect([250])) { fclose($fp); $errorMessage = "Email data transmission failed: $lastResponse"; return false; }

  $send("QUIT");
  fclose($fp);
  return true;
}

/**
 * Convenience wrapper with logging. Every attempt (success or failure) is
 * recorded in the emails_sent table via EmailLog.
 */
function send_email(string $to, string $subject, string $html, string $toName = ''): bool {
  if ($toName === '') $toName = $to;

  $errorMessage = '';
  $success = send_email_with_error($to, $subject, $html, $toName, $errorMessage);

  $ctx = UserContext::getLoggedInUserContext();
  EmailLog::log($ctx, $to, $toName, $subject, $html, $success, $success ? null : $errorMessage);

  return $success;
}

function send_verification_email(string $email, string $token, string $firstName = ''): bool {
  $verifyUrl = Settings::siteBaseUrl() . '/verify_email.php?token=' . urlencode($token);

  $siteTitle = Settings::siteTitle();
  $name = $firstName ? htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

  $html = '<p>Hello ' . $name . ',</p>'
        . '<p>Please verify your email to activate your account for ' . htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>After verifying, you will be prompted to set your password.</p>';

  return send_email($email, 'Verify your ' . $siteTitle . ' account', $html, $name);
}

function send_password_reset_email(string $email, string $token, string $firstName = ''): bool {
  $resetUrl = Settings::siteBaseUrl() . '/reset_password.php?token=' . urlencode($token);

  $siteTitle = Settings::siteTitle();
  $name = $firstName ? htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

  $html = '<p>Hello ' . $name . ',</p>'
        . '<p>You requested a password reset for your ' . htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') . ' account.</p>'
        . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>This link will expire in 30 minutes. If you did not request this reset, you can safely ignore this email.</p>';

  return send_email($email, 'Reset your ' . $siteTitle . ' password', $html, $name);
}
