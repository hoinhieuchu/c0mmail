<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\MailboxRepository;
use App\Services\ImapClient;
use App\Services\MailboxService;
use App\Services\MimeMessageBuilder;
use App\Services\SmtpClient;

final class WebmailController
{
    public function __construct(
        private Auth $auth,
        private MailboxRepository $mailboxes,
        private MailboxService $mailboxService,
        private ImapClient $imap,
        private SmtpClient $smtp,
        private View $view
    ) {}

    public function index(): void
    {
        $user = $this->auth->requireLogin();
        $available = array_values(array_filter($this->mailboxes->forUser((int)$user['id']), static fn(array $m): bool => $m['status'] === 'active'));
        $mailboxId = (int)($_GET['mailbox'] ?? ($available[0]['id'] ?? 0));
        if ($mailboxId < 1) {
            $this->render(['user'=>$user,'mailboxes'=>$available,'error'=>'No active mailbox is assigned to your account.','folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null]);
            return;
        }
        try {
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $folders = $this->imap->folders($context['settings'], $context['credentials']);
            $folderNames = array_column($folders, 'name');
            $folder = (string)($_GET['folder'] ?? 'INBOX');
            if (!in_array($folder, $folderNames, true)) $folder = in_array('INBOX', $folderNames, true) ? 'INBOX' : ($folderNames[0] ?? 'INBOX');
            $page = max(1, min(1000, (int)($_GET['page'] ?? 1)));
            $query = trim((string)($_GET['q'] ?? ''));
            if (strlen($query) > 200) throw new \InvalidArgumentException('Search query is too long.');
            $messages = $this->imap->listMessages($context['settings'], $context['credentials'], $folder, 51, ($page - 1) * 50, $query !== '' ? $query : null);
            $hasMore = count($messages) > 50;
            $messages = array_slice($messages, 0, 50);
            $selected = null;
            $uid = (int)($_GET['uid'] ?? 0);
            if ($uid > 0) $selected = $this->imap->getMessage($context['settings'], $context['credentials'], $folder, $uid);
            $compose = ['to'=>'','subject'=>'','body'=>'','html_body'=>'','in_reply_to'=>'','references'=>''];
            $composeMode = (string)($_GET['compose'] ?? '');
            if ($selected && in_array($composeMode, ['reply', 'forward'], true)) {
                $subject = (string)$selected['subject'];
                $compose['subject'] = (stripos($subject, $composeMode === 'reply' ? 'Re:' : 'Fwd:') === 0 ? $subject : ($composeMode === 'reply' ? 'Re: ' : 'Fwd: ') . $subject);
                if ($composeMode === 'reply') {
                    $from = (string)$selected['from'];
                    if (preg_match('/<([^<>]+)>/', $from, $match)) $from = $match[1];
                    if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) $compose['to'] = trim($from);
                    $quoted = preg_split('/\r\n|\r|\n/', (string)$selected['body']) ?: [];
                    $quoted = implode("\n", array_map(static fn(string $line): string => '> ' . $line, $quoted));
                    $compose['body'] = "\n\nOn " . $selected['date'] . ', ' . $selected['from'] . " wrote:\n" . $quoted;
                    $compose['in_reply_to'] = preg_replace('/\s+/', ' ', trim((string)($selected['message_id'] ?? '')));
                    $references = preg_replace('/\s+/', ' ', trim((string)($selected['references'] ?? '') . ' ' . (string)($selected['message_id'] ?? '')));
                    $compose['references'] = strlen($references) > 998 ? substr($references, -998) : $references;
                } else {
                    $compose['body'] = "---------- Forwarded message ----------\nFrom: " . $selected['from'] . "\nDate: " . $selected['date'] . "\nSubject: " . $selected['subject'] . "\nTo: " . $selected['to'] . "\n\n" . $selected['body'];
                }
            }
            $this->render([
                'user'=>$user,'mailboxes'=>$available,'activeMailbox'=>$context['mailbox'],
                'folders'=>$folders,'messages'=>$messages,'selectedFolder'=>$folder,
                'selectedMessage'=>$selected,'error'=>null,'page'=>$page,'query'=>$query,'hasMore'=>$hasMore,'compose'=>$compose,
            ]);
        } catch (\Throwable $e) {
            error_log('Webmail mailbox view failed: ' . get_class($e));
            $this->render(['user'=>$user,'mailboxes'=>$available,'activeMailbox'=>null,'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null,'error'=>$this->userFacingError($e)]);
        }
    }

    public function send(): void
    {
        $user = $this->auth->requireLogin();
        try {
            $this->assertPostBodyWithinLimit();
            Csrf::verify();
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $submissionId = (string)($_POST['submission_id'] ?? '');
            $submission = $_SESSION['webmail_submissions'][$submissionId] ?? null;
            if (!preg_match('/^[a-f0-9]{32}$/', $submissionId) || !is_array($submission)) {
                throw new \InvalidArgumentException('This compose form has expired. Reload Webmail before sending.');
            }
            if (isset($submission['mailbox_id']) && (int)$submission['mailbox_id'] !== $mailboxId) {
                throw new \InvalidArgumentException('This compose form is not valid for the selected mailbox.');
            }
            if (($submission['status'] ?? '') === 'accepted') {
                $folder = (string)($submission['folder'] ?? 'INBOX');
                $copy = (string)($submission['sent_copy'] ?? 'unavailable');
                header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($folder) . '&sent=1&sent_copy=' . rawurlencode($copy) . '&already=1');
                exit;
            }
            if (($submission['status'] ?? '') !== 'pending') {
                throw new \RuntimeException('This send attempt is already in progress or its delivery status is uncertain. Check Sent before trying again.');
            }
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $rawTo = (string)($_POST['to'] ?? '');
            $recipients = preg_split('/[,;]+/', $rawTo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $recipients = array_values(array_unique(array_map('trim', $recipients)));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            $htmlBody = trim((string)($_POST['html_body'] ?? '')) !== '' ? (string)$_POST['html_body'] : null;
            $attachments = $this->uploadedAttachments();
            if ($subject === '' && trim($body) === '' && $htmlBody === null && !$attachments) throw new \InvalidArgumentException('Email subject or body is required.');
            if (strlen($subject) > 998 || strlen($body) > 2_000_000 || ($htmlBody !== null && strlen($htmlBody) > 2_000_000)) throw new \InvalidArgumentException('Message exceeds the allowed size.');
            $threadHeaders = ['in_reply_to'=>(string)($_POST['in_reply_to'] ?? ''),'references'=>(string)($_POST['references'] ?? '')];
            $_SESSION['webmail_submissions'][$submissionId] = ['status'=>'sending','mailbox_id'=>$mailboxId];
            $messageId = '<' . $submissionId . '@c0m.me>';
            $rawMessage = $this->smtp->send($context['settings'], $context['credentials'], $context['mailbox']['email'], $recipients, $subject, $body, $htmlBody, $attachments, $threadHeaders, $messageId);
            $_SESSION['webmail_submissions'][$submissionId] = ['status'=>'accepted','mailbox_id'=>$mailboxId,'folder'=>'INBOX','sent_copy'=>'unavailable'];
            $sentFolder = null;
            $sentCopy = 'unavailable';
            try {
                $sentFolder = $this->findFolder($this->imap->folders($context['settings'], $context['credentials']), ['sent','sent items','sent mail']);
                if ($sentFolder !== null) {
                    $this->imap->appendRawMessage($context['settings'], $context['credentials'], $sentFolder, $rawMessage, '\\Seen');
                    $sentCopy = 'saved';
                }
            } catch (\Throwable $saveError) {
                $sentCopy = 'failed';
                error_log('Sent-folder copy failed after SMTP accepted message.');
            }
            $_SESSION['webmail_submissions'][$submissionId] = ['status'=>'accepted','mailbox_id'=>$mailboxId,'folder'=>$sentFolder ?? 'INBOX','sent_copy'=>$sentCopy];
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($sentFolder ?? 'INBOX') . '&sent=1&sent_copy=' . $sentCopy);
            exit;
        } catch (\Throwable $e) {
            $submissionId = (string)($_POST['submission_id'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/', $submissionId) && isset($_SESSION['webmail_submissions'][$submissionId])
                && ($_SESSION['webmail_submissions'][$submissionId]['status'] ?? '') === 'sending') {
                $_SESSION['webmail_submissions'][$submissionId]['status'] = 'uncertain';
            }
            error_log('Webmail send failed: ' . get_class($e));
            $this->indexWithError($user, $this->userFacingError($e));
        }
    }



    public function downloadAttachment(): void
    {
        $user = $this->auth->requireLogin();
        try {
            $mailboxId = (int)($_GET['mailbox'] ?? 0);
            $uid = (int)($_GET['uid'] ?? 0);
            $folder = (string)($_GET['folder'] ?? 'INBOX');
            $section = (string)($_GET['section'] ?? '');
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $folders = array_column($this->imap->folders($context['settings'], $context['credentials']), 'name');
            if (!in_array($folder, $folders, true)) throw new \InvalidArgumentException('The selected IMAP folder does not exist.');
            $message = $this->imap->getMessage($context['settings'], $context['credentials'], $folder, $uid);
            $attachment = null;
            foreach ($message['attachments'] as $item) if ($item['section'] === $section) { $attachment = $item; break; }
            if ($attachment === null) { http_response_code(404); exit('Attachment not found.'); }
            if ((int)$attachment['size'] > 25_000_000) { http_response_code(413); exit('Attachment exceeds the 25 MB download limit.'); }
            $data = $this->imap->fetchAttachment($context['settings'], $context['credentials'], $folder, $uid, $section, (int)$attachment['encoding']);
            $filename = basename(str_replace('\\', '/', (string)$attachment['filename']));
            $filename = preg_replace('/[\x00-\x1F\x7F]/', '_', $filename);
            if ($filename === '' || $filename === '.' || $filename === '..') $filename = 'attachment.bin';
            $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string)$attachment['mime']) ? $attachment['mime'] : 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($data));
            header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            echo $data;
            exit;
        } catch (\Throwable $e) {
            error_log('Webmail attachment download failed: ' . get_class($e));
            http_response_code(404);
            exit('Attachment not found.');
        }
    }

    public function saveDraft(): void
    {
        $user = $this->auth->requireLogin();
        try {
            $this->assertPostBodyWithinLimit();
            Csrf::verify();
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $to = preg_split('/[,;]+/', (string)($_POST['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $recipients = array_values(array_unique(array_map('trim', $to)));
            foreach ($recipients as $recipient) if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid draft recipient address.');
            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            $htmlBody = trim((string)($_POST['html_body'] ?? '')) !== '' ? (string)$_POST['html_body'] : null;
            $attachments = $this->uploadedAttachments();
            if (strlen($subject) > 998 || strlen($body) > 2_000_000 || ($htmlBody !== null && strlen($htmlBody) > 2_000_000)) throw new \InvalidArgumentException('Draft exceeds the allowed size.');
            $draftFolder = $this->findFolder($this->imap->folders($context['settings'], $context['credentials']), ['drafts']);
            if ($draftFolder === null) throw new \RuntimeException('The IMAP server does not expose a Drafts folder.');
            $threadHeaders = ['in_reply_to'=>(string)($_POST['in_reply_to'] ?? ''),'references'=>(string)($_POST['references'] ?? '')];
            $rawMessage = MimeMessageBuilder::build($context['mailbox']['email'], $recipients, $subject, $body, $htmlBody, $attachments, $threadHeaders);
            $this->imap->appendRawMessage($context['settings'], $context['credentials'], $draftFolder, $rawMessage, '\\Draft');
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($draftFolder) . '&draft=1');
            exit;
        } catch (\Throwable $e) {
            error_log('Webmail draft save failed: ' . get_class($e));
            $this->indexWithError($user, $this->userFacingError($e));
        }
    }

    private function findFolder(array $folders, array $candidates): ?string
    {
        foreach ($folders as $folder) {
            $name = strtolower((string)$folder['name']);
            $delimiter = (string)($folder['delimiter'] ?? '/');
            foreach ($candidates as $candidate) {
                if ($name === $candidate || str_ends_with($name, $delimiter . $candidate) || str_ends_with($name, '.' . $candidate) || str_ends_with($name, '/' . $candidate)) return (string)$folder['name'];
            }
        }
        return null;
    }


    public function flag(): void
    {
        $user = $this->auth->requireLogin();
        Csrf::verify();
        try {
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $uid = (int)($_POST['uid'] ?? 0);
            $folder = (string)($_POST['folder'] ?? 'INBOX');
            $flag = (string)($_POST['flag'] ?? '');
            $enabled = (string)($_POST['enabled'] ?? '1') === '1';
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $folders = array_column($this->imap->folders($context['settings'], $context['credentials']), 'name');
            if (!in_array($folder, $folders, true)) throw new \InvalidArgumentException('The selected IMAP folder does not exist.');
            $this->imap->setFlag($context['settings'], $context['credentials'], $folder, $uid, $flag, $enabled);
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($folder) . '&flagged=1');
            exit;
        } catch (\Throwable $e) {
            $this->indexWithError($user, $this->userFacingError($e));
        }
    }

    public function move(): void
    {
        $user = $this->auth->requireLogin();
        Csrf::verify();
        try {
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $uid = (int)($_POST['uid'] ?? 0);
            $source = (string)($_POST['source'] ?? 'INBOX');
            $destination = (string)($_POST['destination'] ?? '');
            if (!in_array($destination, ['Trash','Deleted Items','Spam','Junk'], true)) throw new \InvalidArgumentException('Unsupported destination folder.');
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $folders = array_column($this->imap->folders($context['settings'], $context['credentials']), 'name');
            if (!in_array($source, $folders, true) || !in_array($destination, $folders, true)) throw new \InvalidArgumentException('The selected IMAP folder does not exist.');
            $this->imap->move($context['settings'], $context['credentials'], $source, $uid, $destination);
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($source) . '&moved=1');
            exit;
        } catch (\Throwable $e) {
            $this->indexWithError($user, $this->userFacingError($e));
        }
    }

    private function indexWithError(array $user, string $error): void
    {
        $this->render(['user'=>$user,'mailboxes'=>array_values(array_filter($this->mailboxes->forUser((int)$user['id']), static fn(array $m): bool => $m['status'] === 'active')),'activeMailbox'=>null,'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null,'error'=>$error]);
    }

    private function userFacingError(\Throwable $error): string
    {
        if ($error instanceof \InvalidArgumentException) return $error->getMessage();
        $message = $error->getMessage();
        if (str_contains($message, 'IMAP extension is required')) return 'Webmail requires the PHP IMAP extension. Ask the hosting provider to enable it.';
        if (str_contains($message, 'SMTP server rejected')) return 'The SMTP server rejected the message. Check the recipients and mailbox settings.';
        if (str_contains($message, 'SMTP server response timed out') || str_contains($message, 'SMTP server closed')
            || str_contains($message, 'delivery status is uncertain') || str_contains($message, 'already in progress')) {
            return 'The SMTP delivery status may be uncertain. Check the Sent folder before attempting another send.';
        }
        if (str_contains($message, 'Active mailbox not found')) return 'The selected mailbox is unavailable or inactive.';
        return 'The mail operation could not be completed. Check the mail server settings and application logs.';
    }

    private function assertPostBodyWithinLimit(): void
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit = $this->iniSizeBytes((string)ini_get('post_max_size'));
        if ($limit > 0 && $contentLength > $limit && $_POST === [] && $_FILES === []) {
            throw new \InvalidArgumentException('The upload exceeds the server request limit. Increase PHP post_max_size and try again.');
        }
    }

    private function iniSizeBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($unit) {
            'g' => (int)($number * 1024 * 1024 * 1024),
            'm' => (int)($number * 1024 * 1024),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    private function uploadedAttachments(): array
    {
        if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) return [];
        $files = $_FILES['attachments'];
        $count = count($files['name']);
        if ($count > 10) throw new \InvalidArgumentException('A maximum of 10 attachments is allowed.');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $attachments = [];
        $totalBytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) {
                if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    throw new \InvalidArgumentException('An attachment exceeds the PHP upload_max_filesize limit.');
                }
                throw new \RuntimeException('An attachment upload failed.');
            }
            $tmp = (string)($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) throw new \RuntimeException('Invalid attachment upload.');
            $size = (int)($files['size'][$i] ?? 0);
            if ($size < 0 || $size > 25_000_000) throw new \InvalidArgumentException('An attachment exceeds 25 MB.');
            $data = file_get_contents($tmp);
            if ($data === false) throw new \RuntimeException('Unable to read uploaded attachment.');
            $totalBytes += strlen($data);
            if ($totalBytes > 25_000_000) throw new \InvalidArgumentException('Total attachment size exceeds 25 MB.');
            $filename = basename(str_replace('\\', '/', (string)($files['name'][$i] ?? 'attachment.bin')));
            if ($filename === '' || preg_match('/[\x00-\x1F\x7F]/', $filename)) throw new \InvalidArgumentException('Invalid attachment filename.');
            $mime = $finfo->file($tmp) ?: 'application/octet-stream';
            $attachments[] = ['filename'=>$filename,'mime'=>$mime,'data'=>$data];
        }
        return $attachments;
    }

    private function render(array $data): void
    {
        $data['compose'] = array_merge(['to'=>'','subject'=>'','body'=>'','html_body'=>'','in_reply_to'=>'','references'=>''], $data['compose'] ?? []);
        if (empty($data['compose']['submission_id'])) {
            $tokens = $_SESSION['webmail_submissions'] ?? [];
            if (!is_array($tokens)) $tokens = [];
            $token = bin2hex(random_bytes(16));
            $tokens[$token] = ['status'=>'pending'];
            if (count($tokens) > 20) $tokens = array_slice($tokens, -20, null, true);
            $_SESSION['webmail_submissions'] = $tokens;
            $data['compose']['submission_id'] = $token;
        }
        $this->view->render('webmail', array_merge(['title'=>'Webmail','error'=>null,'activeMailbox'=>null,'mailboxes'=>[],'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null,'page'=>1,'query'=>'','hasMore'=>false], $data));
    }
}
