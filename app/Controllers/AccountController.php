<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\PasswordPolicy;

final class AccountController
{
    public function __construct(
        private Auth $auth,
        private UserRepository $users,
        private View $view,
        private PasswordPolicy $passwordPolicy
    ) {}

    public function showPassword(): void
    {
        $user = $this->auth->requireLogin();
        $this->renderPasswordPage($user, null, null);
    }

    public function updatePassword(): void
    {
        $user = $this->auth->requireLogin();
        Csrf::verify();

        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($new !== $confirm) {
            $this->renderPasswordPage($user, 'The new password confirmation does not match.', null);
            return;
        }
        if (($error = $this->passwordPolicy->validate($new)) !== null) {
            $this->renderPasswordPage($user, $error, null);
            return;
        }

        $id = (int)$user['id'];
        $currentHash = $this->users->passwordHashForId($id);
        if ($currentHash === null || !password_verify($current, $currentHash)) {
            $this->renderPasswordPage($user, 'The current password is incorrect.', null);
            return;
        }
        if (password_verify($new, $currentHash)) {
            $this->renderPasswordPage($user, 'Choose a password different from the current password.', null);
            return;
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        if (!$this->users->updatePasswordHash($id, $currentHash, $newHash)) {
            $this->renderPasswordPage($user, 'The password changed in another request. Reload and try again.', null);
            return;
        }

        session_regenerate_id(true);
        $this->renderPasswordPage($user, null, 'Your password has been updated.');
    }

    private function renderPasswordPage(array $user, ?string $error, ?string $success): void
    {
        $this->view->render('account/password', [
            'title' => 'Change password',
            'user' => $user,
            'error' => $error,
            'success' => $success,
        ]);
    }
}
