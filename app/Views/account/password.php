<div class="heading"><div><h1>Change password</h1><p>Update the password used to sign in to c0m.me Mail.</p></div><a class="button secondary" href="/dashboard">Back to dashboard</a></div>
<section class="panel narrow">
<?php if($error): ?><p class="alert" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if($success): ?><p class="notice" role="status"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<form method="post" action="/account/password">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Core\Csrf::token()) ?>">
<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="14" maxlength="1024" required><small>Use at least 14 characters.</small></label>
<label>Confirm new password<input type="password" name="confirm_password" autocomplete="new-password" minlength="14" maxlength="1024" required></label>
<button type="submit">Update password</button>
</form>
</section>
