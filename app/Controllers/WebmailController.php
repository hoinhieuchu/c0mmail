<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\MailboxRepository;
use App\Services\ImapClient;
use App\Services\MailboxService;
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
            $messages = $this->imap->listMessages($context['settings'], $context['credentials'], $folder, 50);
            $selected = null;
            $uid = (int)($_GET['uid'] ?? 0);
            if ($uid > 0) $selected = $this->imap->getMessage($context['settings'], $context['credentials'], $folder, $uid);
            $this->render([
                'user'=>$user,'mailboxes'=>$available,'activeMailbox'=>$context['mailbox'],
                'folders'=>$folders,'messages'=>$messages,'selectedFolder'=>$folder,
                'selectedMessage'=>$selected,'error'=>null,
            ]);
        } catch (\Throwable $e) {
            $this->render(['user'=>$user,'mailboxes'=>$available,'activeMailbox'=>null,'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null,'error'=>$e->getMessage()]);
        }
    }

    public function send(): void
    {
        $user = $this->auth->requireLogin();
        Csrf::verify();
        try {
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $rawTo = (string)($_POST['to'] ?? '');
            $recipients = preg_split('/[,;]+/', $rawTo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $recipients = array_values(array_unique(array_map('trim', $recipients)));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            if ($subject === '' && trim($body) === '') throw new \InvalidArgumentException('Email subject or body is required.');
            if (strlen($subject) > 998 || strlen($body) > 2_000_000) throw new \InvalidArgumentException('Message exceeds the allowed size.');
            $this->smtp->send($context['settings'], $context['credentials'], $context['mailbox']['email'], $recipients, $subject, $body);
            try {
                $sentFolder = $this->findFolder($this->imap->folders($context['settings'], $context['credentials']), ['sent','sent items','sent mail']);
                if ($sentFolder !== null) $this->imap->appendTextMessage($context['settings'], $context['credentials'], $sentFolder, $context['mailbox']['email'], $recipients, $subject, $body, '\Seen');
            } catch (\${name} $saveError) { error_log('Sent-folder copy failed after SMTP accepted message: ' . $saveError->getMessage()); }
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($sentFolder ?? 'INBOX') . '&sent=1');
            exit;
        } catch (\Throwable $e) {
            $this->indexWithError($user, $e->getMessage());
        }
    }


    public function saveDraft(): void
    {
        $user = $this->auth->requireLogin();
        Csrf::verify();
        try {
            $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
            $context = $this->mailboxService->credentialsForUser($mailboxId, (int)$user['id']);
            $to = preg_split('/[,;]+/', (string)($_POST['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $recipients = array_values(array_unique(array_filter(array_map('trim', $to), static fn(string $v): bool => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            if (strlen($subject) > 998 || strlen($body) > 2_000_000) throw new \${name}('Draft exceeds the allowed size.');
            $draftFolder = $this->findFolder($this->imap->folders($context['settings'], $context['credentials']), ['drafts']);
            if ($draftFolder === null) throw new \${name}('The IMAP server does not expose a Drafts folder.');
            $this->imap->appendTextMessage($context['settings'], $context['credentials'], $draftFolder, $context['mailbox']['email'], $recipients, $subject, $body, '\Draft');
            header('Location: /mail?mailbox=' . $mailboxId . '&folder=' . rawurlencode($draftFolder) . '&draft=1');
            exit;
        } catch (\${name} $e) { $this->indexWithError($user, $e->getMessage()); }
    }

    private function findFolder(array $folders, array $candidates): ?string
    {
        foreach ($folders as $folder) {
            $name = strtolower((string)$folder['name']);
            foreach ($candidates as $candidate) {
                if ($name === $candidate || str_ends_with($name, '.' . $candidate)) return (string)$folder['name'];
            }
        }
        return null;
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
            $this->indexWithError($user, $e->getMessage());
        }
    }

    private function indexWithError(array $user, string $error): void
    {
        $this->render(['user'=>$user,'mailboxes'=>array_values(array_filter($this->mailboxes->forUser((int)$user['id']), static fn(array $m): bool => $m['status'] === 'active')),'activeMailbox'=>null,'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null,'error'=>$error]);
    }

    private function render(array $data): void
    {
        $this->view->render('webmail', array_merge(['title'=>'Webmail','error'=>null,'activeMailbox'=>null,'mailboxes'=>[],'folders'=>[],'messages'=>[],'selectedFolder'=>'INBOX','selectedMessage'=>null], $data));
    }
}
