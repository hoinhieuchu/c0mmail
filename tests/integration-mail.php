<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ImapClient;
use App\Services\SmtpClient;

function integrationCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$settings = [
    'imap_host' => 'localhost',
    'imap_port' => 3993,
    'imap_encryption' => 'ssl',
    'smtp_host' => 'localhost',
    'smtp_port' => 3465,
    'smtp_encryption' => 'ssl',
];
$credentials = ['email' => 'test1@example.com', 'password' => 'pwd1'];
$imap = new ImapClient();
$smtp = new SmtpClient();

$folders = $imap->folders($settings, $credentials);
$folderNames = array_column($folders, 'name');
integrationCheck(in_array('INBOX', $folderNames, true), 'GreenMail IMAP folder discovery');
integrationCheck($imap->uidValidity($settings, $credentials, 'INBOX') > 0, 'GreenMail UIDVALIDITY retrieval');

// Create the standard folders needed to exercise APPEND and MOVE.
$prefix = '{localhost:3993/imap/ssl}';
$stream = @imap_open($prefix . 'INBOX', $credentials['email'], $credentials['password'], 0);
if ($stream === false) throw new RuntimeException('Unable to open GreenMail IMAP session for folder setup.');
try {
    $existing = imap_getmailboxes($stream, $prefix, '*') ?: [];
    $existingNames = array_map(static fn($item): string => (string)$item->name, $existing);
    foreach (['Sent', 'Drafts', 'Trash'] as $folder) {
        if (!in_array($prefix . $folder, $existingNames, true)
            && !@imap_createmailbox($stream, $prefix . $folder)) {
            throw new RuntimeException('Unable to create GreenMail test folder: ' . $folder . ' (' . (imap_last_error() ?: 'unknown error') . ')');
        }
    }
} finally {
    imap_close($stream);
}

$subject = 'c0mmail integration ' . bin2hex(random_bytes(4));
$attachmentData = "GreenMail attachment payload\n";
$rawMessage = $smtp->send(
    $settings,
    $credentials,
    $credentials['email'],
    [$credentials['email']],
    $subject,
    'Plain-text integration body',
    '<p>HTML integration body</p>',
    [['filename' => 'báo-cáo-tháng.txt', 'mime' => 'text/plain', 'data' => $attachmentData]]
);
integrationCheck(str_contains($rawMessage, 'Message-ID:'), 'GreenMail SMTP TLS delivery returns the exact MIME payload');

$startTlsSettings = $settings;
$startTlsSettings['smtp_port'] = (int)(getenv('STARTTLS_SMTP_PORT') ?: 3587);
$startTlsSettings['smtp_encryption'] = 'starttls';
$startTlsSubject = $subject . ' STARTTLS';
$startTlsCapture = (string)(getenv('STARTTLS_SMTP_CAPTURE') ?: '/tmp/c0mmail-starttls.eml');
@unlink($startTlsCapture);
$startTlsRaw = $smtp->send(
    $startTlsSettings,
    $credentials,
    $credentials['email'],
    [$credentials['email']],
    $startTlsSubject,
    'STARTTLS integration body',
    null,
    []
);
integrationCheck(str_contains($startTlsRaw, 'Message-ID:'), 'SMTP STARTTLS delivery returns the exact MIME payload');
$capturedStartTlsMessage = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    if (is_file($startTlsCapture)) {
        $captured = (string)file_get_contents($startTlsCapture);
        if (str_contains($captured, $startTlsSubject) && str_contains($captured, 'STARTTLS integration body')) {
            $capturedStartTlsMessage = true;
            break;
        }
    }
    usleep(100_000);
}
integrationCheck($capturedStartTlsMessage, 'SMTP STARTTLS server receives the authenticated message after TLS upgrade');

$messages = [];
for ($attempt = 0; $attempt < 30; $attempt++) {
    $messages = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
    if ($messages) break;
    usleep(200_000);
}
integrationCheck(count($messages) === 1, 'SMTP-delivered message appears in IMAP INBOX');
$uid = (int)$messages[0]['uid'];
$message = $imap->getMessage($settings, $credentials, 'INBOX', $uid);
integrationCheck(str_contains($message['body'], 'Plain-text integration body'), 'IMAP MIME plain-text body retrieval');
integrationCheck(count($message['attachments']) === 1, 'IMAP MIME attachment discovery');
integrationCheck($message['attachments'][0]['filename'] === 'báo-cáo-tháng.txt', 'RFC 2231 Unicode attachment filename round-trip');
$attachment = $message['attachments'][0];
$downloaded = $imap->fetchAttachment($settings, $credentials, 'INBOX', $uid, $attachment['section'], (int)$attachment['encoding']);
integrationCheck($downloaded === $attachmentData, 'IMAP attachment transfer decoding');

$imap->setFlag($settings, $credentials, 'INBOX', $uid, 'Flagged', true);
$flagged = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
integrationCheck(!empty($flagged[0]['flagged']), 'IMAP flagged state update');
$imap->setFlag($settings, $credentials, 'INBOX', $uid, 'Seen', false);
$unread = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
integrationCheck(empty($unread[0]['seen']), 'IMAP seen state update');

$imap->appendRawMessage($settings, $credentials, 'Sent', $rawMessage, '\\Seen');
$sent = $imap->listMessages($settings, $credentials, 'Sent', 10, 0, $subject);
integrationCheck(count($sent) === 1, 'IMAP APPEND to Sent folder');
$imap->appendRawMessage($settings, $credentials, 'Drafts', $rawMessage, '\\Draft');
$drafts = $imap->listMessages($settings, $credentials, 'Drafts', 10, 0, $subject);
integrationCheck(count($drafts) === 1, 'IMAP APPEND to Drafts folder');

$imap->move($settings, $credentials, 'INBOX', $uid, 'Trash');
$inboxAfterMove = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
$trash = $imap->listMessages($settings, $credentials, 'Trash', 10, 0, $subject);
integrationCheck($inboxAfterMove === [], 'IMAP MOVE removes the message from source search');
integrationCheck(count($trash) === 1, 'IMAP MOVE places the message in destination folder');

echo "GreenMail integration checks passed.\n";
