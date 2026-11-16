<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ImapClient;
use App\Services\SmtpClient;
use App\Services\SmtpDeliveryException;

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
$smtpChecker = new \\App\\Services\\SmtpConnectionChecker();
$smtpChecker->verify($settings, $credentials);
integrationCheck(true, 'SMTP implicit TLS authentication and NOOP connection check');

$folders = $imap->folders($settings, $credentials);
$folderNames = array_column($folders, 'name');
integrationCheck(in_array('INBOX', $folderNames, true), 'GreenMail IMAP folder discovery');
integrationCheck($imap->uidValidity($settings, $credentials, 'INBOX') > 0, 'GreenMail UIDVALIDITY retrieval');
integrationCheck($imap->listMessages($settings, $credentials, 'INBOX', 10, 0, 'c0mmail-no-such-message-' . bin2hex(random_bytes(4))) === [], 'IMAP empty search returns an empty page without a protocol error');

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
    [['filename' => 'báo-cáo-tháng.txt', 'mime' => 'text/plain', 'data' => $attachmentData]],
    [], null, [], [], 'Nguyễn Văn A', 'reply@example.test'
);
integrationCheck(str_contains($rawMessage, 'Message-ID:'), 'GreenMail SMTP TLS delivery returns the exact MIME payload');
integrationCheck(str_contains($rawMessage, '=?UTF-8?B?'), 'SMTP MIME includes an encoded sender display name');
integrationCheck(str_contains($rawMessage, 'Reply-To: <reply@example.test>'), 'SMTP MIME includes Reply-To');

$startTlsSettings = $settings;
$startTlsSettings['smtp_host'] = '127.0.0.1';
$startTlsSettings['smtp_port'] = (int)(getenv('STARTTLS_SMTP_PORT') ?: 3587);
$startTlsSettings['smtp_encryption'] = 'starttls';
$smtpChecker->verify($startTlsSettings, $credentials);
integrationCheck(true, 'SMTP STARTTLS authentication and NOOP connection check');
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

$rejectSettings = $startTlsSettings;
$rejectSettings['smtp_port'] = (int)(getenv('SMTP_REJECT_PORT') ?: 3588);
try {
    $smtp->send($rejectSettings, $credentials, $credentials['email'], [$credentials['email']],
        $subject . ' explicit rejection', 'Rejected after DATA', null, []);
    throw new RuntimeException('Expected an explicit SMTP 550 rejection after DATA.');
} catch (SmtpDeliveryException $e) {
    integrationCheck(!$e->deliveryUncertain, 'Explicit SMTP 5xx after DATA is classified as definitive rejection');
}

$dropSettings = $startTlsSettings;
$dropSettings['smtp_port'] = (int)(getenv('SMTP_DROP_PORT') ?: 3589);
try {
    $smtp->send($dropSettings, $credentials, $credentials['email'], [$credentials['email']],
        $subject . ' lost final reply', 'Connection dropped after DATA', null, []);
    throw new RuntimeException('Expected SMTP connection loss after DATA.');
} catch (SmtpDeliveryException $e) {
    integrationCheck($e->deliveryUncertain, 'Connection loss after DATA is classified as uncertain delivery');
}


$messages = [];
for ($attempt = 0; $attempt < 30; $attempt++) {
    
$threadToken = bin2hex(random_bytes(5));
$threadSubject = 'c0mmail cross-page conversation ' . $threadToken;
$rootId = '<thread-root-' . $threadToken . '@example.test>';
$replyId = '<thread-reply-' . $threadToken . '@example.test>';
$followupId = '<thread-followup-' . $threadToken . '@example.test>';
$threadMessages = [
    \App\Services\MimeMessageBuilder::build($credentials['email'], [$credentials['email']], $threadSubject, 'Conversation root', null, [], [], $rootId),
    \App\Services\MimeMessageBuilder::build($credentials['email'], [$credentials['email']], 'Re: ' . $threadSubject, 'First reply', null, [], ['in_reply_to' => $rootId, 'references' => $rootId], $replyId),
    \App\Services\MimeMessageBuilder::build($credentials['email'], [$credentials['email']], 'Re: ' . $threadSubject, 'Second reply', null, [], ['in_reply_to' => $replyId, 'references' => $rootId . ' ' . $replyId], $followupId),
];
foreach ($threadMessages as $threadRaw) {
    $imap->appendRawMessage($settings, $credentials, 'INBOX', $threadRaw, '\\Seen');
}
$threadRows = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $threadSubject);
$rootRow = null;
foreach ($threadRows as $threadRow) {
    if ($threadRow['message_id'] === $rootId) { $rootRow = $threadRow; break; }
}
if ($rootRow === null) throw new RuntimeException('Unable to locate the root message of the conversation test.');
$conversationRows = $imap->conversationMessages($settings, $credentials, 'INBOX', (int)$rootRow['uid']);
$conversationIds = array_column($conversationRows, 'message_id');
integrationCheck(count($conversationRows) === 3, 'IMAP conversation search resolves messages across the full folder');
integrationCheck(in_array($rootId, $conversationIds, true) && in_array($replyId, $conversationIds, true) && in_array($followupId, $conversationIds, true), 'IMAP conversation search follows References and In-Reply-To chains');

$messages = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
    if ($messages) break;
    usleep(200_000);
}
integrationCheck(count($messages) === 1, 'SMTP-delivered message appears in IMAP INBOX');
$uid = (int)$messages[0]['uid'];
$message = $imap->getMessage($settings, $credentials, 'INBOX', $uid);
integrationCheck(str_contains($message['body'], 'Plain-text integration body'), 'IMAP MIME plain-text body retrieval');
integrationCheck($message['seen'] === true, 'getMessage marks opened messages as seen');
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

$protectedSubject = 'c0mmail unrelated deleted ' . bin2hex(random_bytes(4));
$protectedRaw = \App\Services\MimeMessageBuilder::build(
    $credentials['email'], [$credentials['email']], $protectedSubject, 'Unrelated message already marked deleted'
);
$imap->appendRawMessage($settings, $credentials, 'INBOX', $protectedRaw, '\\Seen');
$protectedRows = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $protectedSubject);
if (count($protectedRows) !== 1) throw new RuntimeException('Unable to create unrelated pre-deleted test message.');
$protectedUid = (int)$protectedRows[0]['uid'];
$inboxPrefix = '{localhost:3993/imap/ssl}';
$deleteStream = @imap_open($inboxPrefix . 'INBOX', $credentials['email'], $credentials['password'], 0);
if ($deleteStream === false) throw new RuntimeException('Unable to open INBOX for pre-delete safety test.');
try {
    if (!imap_setflag_full($deleteStream, (string)$protectedUid, '\\Deleted', ST_UID)) {
        throw new RuntimeException('Unable to mark unrelated test message as deleted.');
    }
} finally {
    imap_close($deleteStream);
}

$imap->move($settings, $credentials, 'INBOX', $uid, 'Trash');
$inboxAfterMove = $imap->listMessages($settings, $credentials, 'INBOX', 10, 0, $subject);
$trash = $imap->listMessages($settings, $credentials, 'Trash', 10, 0, $subject);
$verifyStream = @imap_open($inboxPrefix . 'INBOX', $credentials['email'], $credentials['password'], OP_READONLY);
if ($verifyStream === false) throw new RuntimeException('Unable to verify unrelated deleted message retention.');
try {
    $deletedUids = imap_search($verifyStream, 'DELETED', SE_UID) ?: [];
} finally {
    imap_close($verifyStream);
}
integrationCheck(in_array($protectedUid, array_map('intval', $deletedUids), true), 'IMAP MOVE preserves unrelated messages already marked Deleted');

integrationCheck($inboxAfterMove === [], 'IMAP MOVE removes the message from source search');
integrationCheck(count($trash) === 1, 'IMAP MOVE places the message in destination folder');

echo "GreenMail integration checks passed.\n";
