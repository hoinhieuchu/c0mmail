#!/usr/bin/env bash
set -euo pipefail
php bin/create-admin.php ci-admin@example.test 'ci-password-123456789'
php -S 127.0.0.1:18080 -t public public/index.php >/tmp/c0mmail-http.log 2>&1 &
server_pid=$!
trap 'status=$?; kill "$server_pid" 2>/dev/null || true; if [ "$status" -ne 0 ]; then cat /tmp/c0mmail-http.log; fi; exit "$status"' EXIT
for attempt in {1..30}; do
  if curl -fsS http://127.0.0.1:18080/login -o /tmp/c0mmail-login.html; then break; fi
  sleep 1
done
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies http://127.0.0.1:18080/login -o /tmp/c0mmail-login.html
grep -q 'Sign in' /tmp/c0mmail-login.html
csrf=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-login.html | head -n1)
test -n "$csrf"
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-login-headers -o /tmp/c0mmail-login-result \
  -X POST http://127.0.0.1:18080/login \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'email=ci-admin@example.test' \
  --data-urlencode 'password=ci-password-123456789'
grep -qi '^Location: /dashboard' /tmp/c0mmail-login-headers
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/account/password -o /tmp/c0mmail-password.html
csrf=$(grep -oP 'name="_csrf" value="\\K[^"]+' /tmp/c0mmail-password.html | head -n1)
test -n "$csrf"
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/dashboard -o /tmp/c0mmail-dashboard.html
grep -q 'Your mailboxes' /tmp/c0mmail-dashboard.html
grep -qi '^X-Content-Type-Options: nosniff' /tmp/c0mmail-login-headers
grep -qi '^X-Frame-Options: DENY' /tmp/c0mmail-login-headers
grep -qi '^Content-Security-Policy:' /tmp/c0mmail-login-headers
grep -q 'ci-admin@example.test' /tmp/c0mmail-dashboard.html
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/account/password -o /tmp/c0mmail-password.html
grep -q 'Change password' /tmp/c0mmail-password.html
new_password='ci-password-changed-987654'
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -o /tmp/c0mmail-password-result \
  -X POST http://127.0.0.1:18080/account/password \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'current_password=ci-password-123456789' \
  --data-urlencode "new_password=$new_password" \
  --data-urlencode "confirm_password=$new_password"
grep -q 'Your password has been updated' /tmp/c0mmail-password-result
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/admin/mailboxes -o /tmp/c0mmail-admin.html
grep -q 'Mailbox administration' /tmp/c0mmail-admin.html
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/admin/members -o /tmp/c0mmail-members.html
grep -q 'Member administration' /tmp/c0mmail-members.html
member_email='ci-member@example.test'
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -o /dev/null -X POST http://127.0.0.1:18080/admin/members \
  --data-urlencode "_csrf=$csrf" --data-urlencode "email=$member_email" \
  --data-urlencode 'display_name=CI Member' --data-urlencode 'password=ci-member-password-123456'
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/admin/members -o /tmp/c0mmail-members-created.html
grep -q "$member_email" /tmp/c0mmail-members-created.html
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-mailbox-create-headers -o /tmp/c0mmail-mailbox-created.html \
  -X POST http://127.0.0.1:18080/admin/mailboxes \
  --data-urlencode "_csrf=$csrf" --data-urlencode "user_id=$(grep -oP 'action="/admin/members/\K[0-9]+' /tmp/c0mmail-members-created.html | head -n1)" \
  --data-urlencode 'local_part=ci-mailbox' --data-urlencode 'display_name=CI Mailbox' \
  --data-urlencode 'password=ci-mailbox-password-123456'
grep -q 'ci-mailbox-password-123456' /tmp/c0mmail-mailbox-created.html
grep -qi '^Cache-Control: no-store, private' /tmp/c0mmail-mailbox-create-headers
session_id=$(awk '$6=="c0mmail_ci"{print $7}' /tmp/c0mmail-cookies | tail -n1)
session_dir=$(php -r 'echo session_save_path();')
if [ -n "$session_dir" ] && [ -n "$session_id" ] && [ -f "$session_dir/sess_$session_id" ]; then
  ! grep -Fq 'ci-mailbox-password-123456' "$session_dir/sess_$session_id"
fi
member_id=$(grep -oP 'action="/admin/members/\K[0-9]+(?=/status)' /tmp/c0mmail-members-created.html | head -n1)
test -n "$member_id"
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -o /dev/null -X POST "http://127.0.0.1:18080/admin/members/$member_id/status" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'status=suspended'
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/admin/members -o /tmp/c0mmail-members-suspended.html
grep -q 'suspended' /tmp/c0mmail-members-suspended.html
curl -fsS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-logout-headers -o /dev/null \
  -X POST http://127.0.0.1:18080/logout --data-urlencode "_csrf=$csrf"
grep -qi '^Location: /login' /tmp/c0mmail-logout-headers
curl -sS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-protected-headers -o /dev/null http://127.0.0.1:18080/dashboard
grep -qi '^Location: /login' /tmp/c0mmail-protected-headers
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies http://127.0.0.1:18080/login -o /tmp/c0mmail-login-again.html
csrf_again=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-login-again.html | head -n1)
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-old-login-headers -o /tmp/c0mmail-old-login-result \
  -X POST http://127.0.0.1:18080/login --data-urlencode "_csrf=$csrf_again" \
  --data-urlencode 'email=ci-admin@example.test' --data-urlencode 'password=ci-password-123456789'
grep -q 'Email or password is incorrect' /tmp/c0mmail-old-login-result
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -o /tmp/c0mmail-suspended-login-result \
  -X POST http://127.0.0.1:18080/login --data-urlencode "_csrf=$csrf_again" \
  --data-urlencode 'email=ci-member@example.test' --data-urlencode 'password=ci-member-password-123456'
grep -q 'Email or password is incorrect' /tmp/c0mmail-suspended-login-result
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-new-login-headers -o /tmp/c0mmail-new-login-result \
  -X POST http://127.0.0.1:18080/login --data-urlencode "_csrf=$csrf_again" \
  --data-urlencode 'email=ci-admin@example.test' --data-urlencode "password=$new_password"
grep -qi '^Location: /dashboard' /tmp/c0mmail-new-login-headers
echo 'HTTP smoke suite passed.'
