#!/usr/bin/env bash
set -euo pipefail
php bin/create-admin.php ci-admin@example.test 'ci-password-123456789'
php -S 127.0.0.1:8080 -t public public/index.php >/tmp/c0mmail-http.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
for attempt in {1..30}; do
  if curl -fsS http://127.0.0.1:8080/login -o /tmp/c0mmail-login.html; then break; fi
  sleep 1
done
grep -q 'Sign in' /tmp/c0mmail-login.html
csrf=$(grep -oP 'name="_csrf" value="\K[^"]+' /tmp/c0mmail-login.html | head -n1)
test -n "$csrf"
curl -fsS -c /tmp/c0mmail-cookies -b /tmp/c0mmail-cookies -D /tmp/c0mmail-login-headers -o /tmp/c0mmail-login-result \
  -X POST http://127.0.0.1:8080/login \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'email=ci-admin@example.test' \
  --data-urlencode 'password=ci-password-123456789'
grep -qi '^Location: /dashboard' /tmp/c0mmail-login-headers
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:8080/dashboard -o /tmp/c0mmail-dashboard.html
grep -q 'Your mailboxes' /tmp/c0mmail-dashboard.html
grep -q 'ci-admin@example.test' /tmp/c0mmail-dashboard.html
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:8080/account/password -o /tmp/c0mmail-password.html
grep -q 'Change password' /tmp/c0mmail-password.html
curl -fsS -b /tmp/c0mmail-cookies http://127.0.0.1:8080/admin/mailboxes -o /tmp/c0mmail-admin.html
grep -q 'Mailbox administration' /tmp/c0mmail-admin.html
curl -fsS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-logout-headers -o /dev/null \
  -X POST http://127.0.0.1:8080/logout --data-urlencode "_csrf=$csrf"
grep -qi '^Location: /login' /tmp/c0mmail-logout-headers
curl -sS -b /tmp/c0mmail-cookies -D /tmp/c0mmail-protected-headers -o /dev/null http://127.0.0.1:8080/dashboard
grep -qi '^Location: /login' /tmp/c0mmail-protected-headers
echo 'HTTP smoke suite passed.'
