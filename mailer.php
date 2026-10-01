<?php
// Minimal SMTP mailer (no libraries needed). Used by dashboard.php to email teacher logins.

function mail_config() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/mail_config.php';
    return $c;
}

function mail_is_configured() {
    $c = mail_config();
    return !empty($c['username']) && !empty($c['password'])
        && strpos($c['username'], 'YOUR_') === false && strpos($c['password'], 'YOUR_') === false;
}

// The login page address that goes into the email
function portal_login_url() {
    $c = mail_config();
    if (!empty($c['site_url'])) return rtrim($c['site_url'], '/') . '/login.php';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/login.php';
}

function smtp_read($fp) {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;   // last line of a reply
    }
    return $data;
}

function smtp_cmd($fp, $cmd, $expect, &$err) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $resp = smtp_read($fp);
    if (strpos($resp, $expect) !== 0) {
        $err = trim($resp) !== '' ? trim($resp) : 'No reply from the mail server (timed out).';
        return false;
    }
    return true;
}

// Returns true on success; on failure returns false and fills $err.
function send_mail($to, $subject, $body, &$err = '') {
    if (!mail_is_configured()) { $err = 'Email is not set up yet. Open mail_config.php and enter the school email and App Password.'; return false; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $err = 'The email address is not valid.'; return false; }

    $c = mail_config();
    $verify = !empty($c['verify_ssl']);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]]);
    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'];

    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "Could not connect to the mail server ($errstr). Check your internet connection."; return false; }
    stream_set_timeout($fp, 20);

    $ok = smtp_cmd($fp, null, '220', $err);
    if ($ok) $ok = smtp_cmd($fp, 'EHLO localhost', '250', $err);
    if ($ok && $c['secure'] === 'tls') {
        $ok = smtp_cmd($fp, 'STARTTLS', '220', $err);
        if ($ok && !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'Could not start a secure connection. In WampServer enable the php_openssl extension.';
            $ok = false;
        }
        if ($ok) $ok = smtp_cmd($fp, 'EHLO localhost', '250', $err);
    }
    if ($ok) $ok = smtp_cmd($fp, 'AUTH LOGIN', '334', $err);
    if ($ok) $ok = smtp_cmd($fp, base64_encode($c['username']), '334', $err);
    if ($ok) {
        $ok = smtp_cmd($fp, base64_encode($c['password']), '235', $err);
        if (!$ok) $err = 'The mail server refused the username/App Password. (' . $err . ')';
    }
    if ($ok) $ok = smtp_cmd($fp, 'MAIL FROM:<' . $c['from_email'] . '>', '250', $err);
    if ($ok) $ok = smtp_cmd($fp, 'RCPT TO:<' . $to . '>', '250', $err);
    if ($ok) $ok = smtp_cmd($fp, 'DATA', '354', $err);

    if ($ok) {
        $fromName = '=?UTF-8?B?' . base64_encode($c['from_name']) . '?=';
        $headers  = 'Date: ' . date('r') . "\r\n";
        $headers .= 'From: ' . $fromName . ' <' . $c['from_email'] . ">\r\n";
        $headers .= 'To: <' . $to . ">\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $headers .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $c['host']) . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
        $ok = smtp_cmd($fp, null, '250', $err);
    }

    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
