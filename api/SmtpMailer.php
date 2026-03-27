<?php
/**
* Simple SMTP mailer class (without external dependencies)
* Supports TLS/SSL encryption 
**/

class SmtpMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $socket;
    private $lastError = '';
    private $debug = false;
    private $logFunction = null;

    public function __construct($host, $port, $username, $password, $encryption = 'tls') {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = strtolower($encryption);
    }

    public function setDebug($debug) {
        $this->debug = $debug;
    }

    public function setLogFunction($func) {
        $this->logFunction = $func;
    }

    private function log($message) {
        if ($this->logFunction && is_callable($this->logFunction)) {
            call_user_func($this->logFunction, $message);
        }
        if ($this->debug) {
            error_log($message);
        }
    }

    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Sends an email via SMTP
     */
    public function send($to, $subject, $body, $fromEmail, $fromName = '', $replyTo = '') {
        try {
            // Connect to the server
            if (!$this->connect()) {
                return false;
            }

            // Send EHLO
            if (!$this->ehlo()) {
                $this->disconnect();
                return false;
            }

            // Start TLS if necessary
            if ($this->encryption === 'tls') {
                if (!$this->startTls()) {
                    $this->disconnect();
                    return false;
                }
                // After STARTTLS, send EHLO again
                if (!$this->ehlo()) {
                    $this->disconnect();
                    return false;
                }
            }

            // authentication
            if (!$this->authenticate()) {
                $this->disconnect();
                return false;
            }

            // Send email
            if (!$this->sendMail($to, $subject, $body, $fromEmail, $fromName, $replyTo)) {
                $this->disconnect();
                return false;
            }

            $this->disconnect();
            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }

    private function connect() {
        $host = $this->host;

        // SSL prefix for port 465
        if ($this->encryption === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $this->log("Connecting to $host:$this->port ...");

        $this->socket = @fsockopen($host, $this->port, $errno, $errstr, 30);

        if (!$this->socket) {
            $this->lastError = "Connection failed: $errstr ($errno)";
            $this->log("ERROR: " . $this->lastError);
            return false;
        }

        $this->log("Connection established, waiting for greeting...");

        // Read server greeting
        $response = $this->getResponse();
        if (substr($response, 0, 3) !== '220') {
            $this->lastError = "Invalid server response: $response";
            $this->log("ERROR: " . $this->lastError);
            return false;
        }

        $this->log("Server greeting OK");
        return true;
    }

    private function disconnect() {
        if ($this->socket) {
            $this->sendCommand("QUIT");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function ehlo() {
        $hostname = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        $response = $this->sendCommand("EHLO $hostname");
        return substr($response, 0, 3) === '250';
    }

    private function startTls() {
        $response = $this->sendCommand("STARTTLS");
        if (substr($response, 0, 3) !== '220') {
            $this->lastError = "STARTTLS failed: $response";
            return false;
        }

        // Enable TLS encryption
        $crypto = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            $this->lastError = "Failed to enable TLS encryption";
            return false;
        }

        return true;
    }

    private function authenticate() {
        // AUTH LOGIN
        $response = $this->sendCommand("AUTH LOGIN");
        if (substr($response, 0, 3) !== '334') {
            $this->lastError = "AUTH LOGIN failed: $response";
            return false;
        }

        // Username (Base64)
        $response = $this->sendCommand(base64_encode($this->username));
        if (substr($response, 0, 3) !== '334') {
            $this->lastError = "Username rejected: $response";
            return false;
        }

        // Password (Base64)
        $response = $this->sendCommand(base64_encode($this->password));
        if (substr($response, 0, 3) !== '235') {
            $this->lastError = "Authentication failed: $response";
            return false;
        }

        return true;
    }

    private function sendMail($to, $subject, $body, $fromEmail, $fromName, $replyTo) {
        // MAIL FROM
        $response = $this->sendCommand("MAIL FROM:<$fromEmail>");
        if (substr($response, 0, 3) !== '250') {
            $this->lastError = "MAIL FROM rejected: $response";
            return false;
        }

        // RCPT TO
        $response = $this->sendCommand("RCPT TO:<$to>");
        if (substr($response, 0, 3) !== '250') {
            $this->lastError = "RCPT TO rejected: $response";
            return false;
        }

        // DATA
        $response = $this->sendCommand("DATA");
        if (substr($response, 0, 3) !== '354') {
            $this->lastError = "DATA rejected: $response";
            return false;
        }

        // Email headers and body
        $headers = [];
        $headers[] = "Date: " . date('r');
        $headers[] = "From: " . ($fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>" : $fromEmail);
        $headers[] = "To: <$to>";
        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";

        if ($replyTo) {
            $headers[] = "Reply-To: <$replyTo>";
        }

        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        $headers[] = "X-Mailer: SmtpMailer/1.0";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // Escape dots at beginning of lines (SMTP protocol)
        $message = str_replace("\n.", "\n..", $message);

        // Send message and terminate with .
        fwrite($this->socket, $message . "\r\n.\r\n");
        $response = $this->getResponse();

        if (substr($response, 0, 3) !== '250') {
            $this->lastError = "Email not accepted: $response";
            return false;
        }

        return true;
    }

    private function sendCommand($command) {
        // Don't log password
        $logCmd = (strpos($command, base64_encode($this->password)) !== false)
            ? '[PASSWORD]'
            : $command;
        $this->log("SMTP > $logCmd");

        fwrite($this->socket, $command . "\r\n");
        return $this->getResponse();
    }

    private function getResponse() {
        $response = '';

        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            // Check if last line (4th character is a space)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $this->log("SMTP < " . trim($response));

        return $response;
    }
}
