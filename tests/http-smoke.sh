#!/usr/bin/env bash
set -euo pipefail

admin_email='ci-admin@example.test'
admin_password='ci-password-123456789'
member_email='ci-member@example.test'

php bin/create-admin.php "$admin_email" "$admin_password"

php -S 127.0.0.1:18080 -t public public/index.php >/tmp/c0mmail-http.log 2>&1 &
server_pid=$!
trap 'status=$?; kill "$server_pid" 2>/dev/null || true; if [ "$status" -ne 0 ]; then cat /tmp/c0mmail-http.log; fi; exit "$status"' EXIT

ready=0
for attempt in {1..30}; do
  if curl -fsS http://127.0.0.1:18080/ -o /tmp/c0mmail-login.html; then ready=1; break; fi
  sleep 1
done
test "$ready" -eq 1
grep -q 'Sign in' /tmp/c0mmail-login.html

csrf=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-login.html | head -n1)
test -n "$csrf"
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-login-headers -o /tmp/c0mmail-login-result \
  -X POST http://127.0.0.1:18080/login \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "email=$admin_email" \
  --data-urlencode "password=$admin_password"
grep -qi '^Location: /dashboard' /tmp/c0mmail-login-headers

curl -fsS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-dashboard-headers http://127.0.0.1:18080/dashboard -o /tmp/c0mmail-dashboard.html
grep -q 'Your mailboxes' /tmp/c0mmail-dashboard.html
grep -q "$admin_email" /tmp/c0mmail-dashboard.html
grep -qi '^X-Content-Type-Options: nosniff' /tmp/c0mmail-dashboard-headers
grep -qi '^X-Frame-Options: DENY' /tmp/c0mmail-dashboard-headers
grep -qi '^Content-Security-Policy:' /tmp/c0mmail-dashboard-headers
grep -qi '^Cache-Control: private, no-store' /tmp/c0mmail-dashboard-headers

curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:18080/admin/mailboxes -o /tmp/c0mmail-admin.html
grep -q 'Mailbox administration' /tmp/c0mmail-admin.html
member_id=$(grep -oP '<option value="\K[0-9]+(?=">'"$member_email"'</option>)' /tmp/c0mmail-admin.html | head -n1)
test -n "$member_id"
csrf=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-admin.html | head -n1)
test -n "$csrf"

curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-mailbox-create-headers -o /dev/null \
  -X POST http://127.0.0.1:18080/admin/mailboxes \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "user_id=$member_id" \
  --data-urlencode 'local_part=ci-mailbox' \
  --data-urlencode 'display_name=CI Mailbox' \
  --data-urlencode 'password=ci-mailbox-password-123456'
grep -qi '^Location: /admin/mailboxes' /tmp/c0mmail-mailbox-create-headers

curl -fsS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-mailbox-list-headers http://127.0.0.1:18080/admin/mailboxes -o /tmp/c0mmail-mailbox-created.html
grep -q 'ci-mailbox@example.test' /tmp/c0mmail-mailbox-created.html
grep -q 'ci-mailbox-password-123456' /tmp/c0mmail-mailbox-created.html
grep -qi '^Cache-Control: private, no-store' /tmp/c0mmail-mailbox-list-headers
session_id=$(awk '$6=="c0mmail_ci"{print $7}' /tmp/c0mmail-cookies | tail -n1)
session_dir=$(php -r 'echo session_save_path();')
if [ -n "$session_dir" ] && [ -n "$session_id" ] && [ -f "$session_dir/sess_$session_id" ]; then
  ! grep -Fq 'ci-mailbox-password-123456' "$session_dir/sess_$session_id"
fi

csrf=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-mailbox-created.html | head -n1)
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-logout-headers -o /dev/null \
  -X POST http://127.0.0.1:18080/logout --data-urlencode "_csrf=$csrf"
grep -qi '^Location: /login' /tmp/c0mmail-logout-headers

curl -sS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-protected-headers -o /dev/null http://127.0.0.1:18080/dashboard
grep -qi '^Location: /login' /tmp/c0mmail-protected-headers

echo 'HTTP smoke suite passed.'
