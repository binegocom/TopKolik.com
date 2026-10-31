#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=18889
TEMP="$(mktemp -d)"
SERVER_PID=""

cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$TEMP"
}
trap cleanup EXIT

bash "$ROOT/scripts/check-dev-tools.sh"

export APP_URL="http://127.0.0.1:$PORT"
export MAIL_MAILER=smtp
export MAIL_HOST=127.0.0.1
export MAIL_PORT=51025
export MAIL_FROM_ADDRESS=verify@example.test
export MAIL_FROM_NAME=Topkolik
export READER_PASSWORD_RESET_URL="http://127.0.0.1:$PORT/reset-password"
export STAFF_INVITATION_ACCEPT_URL="http://127.0.0.1:$PORT/staff/accept-invitation"

cd "$ROOT/services/api"
php artisan serve --host=127.0.0.1 --port="$PORT" >"$TEMP/server.log" 2>&1 &
SERVER_PID="$!"

ready=0
for ((attempt=1; attempt<=30; attempt++)); do
  if curl --fail --silent --max-time 2 "http://127.0.0.1:$PORT/health" >/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" != 1 ]]; then
  cat "$TEMP/server.log" >&2
  echo "Laravel HTTP server did not start" >&2
  exit 1
fi

python3 - "$PORT" <<'PY'
import json
import secrets
import sys
import time
import urllib.error
import urllib.request

port = int(sys.argv[1])
email = f"ci-{secrets.token_hex(8)}@example.test"
body = {
    "name": "CI Reader",
    "email": email,
    "password": "Verify!EmailExample2026",
    "password_confirmation": "Verify!EmailExample2026",
}
request = urllib.request.Request(
    f"http://127.0.0.1:{port}/api/v1/auth/register",
    data=json.dumps(body).encode(),
    headers={"Content-Type": "application/json", "Accept": "application/json"},
    method="POST",
)
with urllib.request.urlopen(request, timeout=5) as response:
    assert response.status == 202, response.status

for attempt in range(30):
    try:
        with urllib.request.urlopen("http://127.0.0.1:58025/view/latest.txt", timeout=3) as response:
            message = response.read().decode("utf-8")
        if "auth/email/verify/" in message:
            print("PASS: Laravel registration sent a signed verification email over SMTP to Mailpit.")
            break
    except (urllib.error.URLError, TimeoutError):
        pass
    time.sleep(1)
else:
    raise SystemExit("FAIL: Mailpit did not receive the signed Laravel verification email.")

forgot_request = urllib.request.Request(
    f"http://127.0.0.1:{port}/api/v1/auth/password/forgot",
    data=json.dumps({"email": email}).encode(),
    headers={"Content-Type": "application/json", "Accept": "application/json"},
    method="POST",
)
with urllib.request.urlopen(forgot_request, timeout=5) as response:
    assert response.status == 202, response.status
    assert b"token" not in response.read(), "Reset token leaked in API response"

for attempt in range(30):
    try:
        with urllib.request.urlopen("http://127.0.0.1:58025/view/latest.txt", timeout=3) as response:
            message = response.read().decode("utf-8")
        if "reset-password?token=" in message and "email=" in message:
            print("PASS: Laravel sent a password reset notification over real SMTP to Mailpit.")
            break
    except (urllib.error.URLError, TimeoutError):
        pass
    time.sleep(1)
else:
    raise SystemExit("FAIL: Mailpit did not receive the password reset email.")
PY

# Use only a CI-owned mailbox. The raw invitation secret is never printed.
php artisan staff:invite "ci-staff@example.test" --role=editor --operator=github-ci >"$TEMP/staff-invite-output"

python3 - "$PORT" <<'PY'
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

port = int(sys.argv[1])
for attempt in range(30):
    try:
        with urllib.request.urlopen("http://127.0.0.1:58025/view/latest.txt", timeout=3) as response:
            mail_body = response.read().decode("utf-8")
        match = re.search(r"http://127\.0\.0\.1:\d+/staff/accept-invitation\?invitation=[^\s]+", mail_body)
        if match:
            params = urllib.parse.parse_qs(urllib.parse.urlparse(match.group(0)).query)
            invite_id, token = params["invitation"][0], params["token"][0]
            assert len(token) == 64
            request = urllib.request.Request(
                f"http://127.0.0.1:{port}/api/v1/staff/invitations/accept",
                data=json.dumps({
                    "invitation": invite_id,
                    "token": token,
                    "name": "CI Staff Member",
                    "password": "CI!StaffSecurePassword2026",
                    "password_confirmation": "CI!StaffSecurePassword2026",
                }).encode(),
                headers={"Content-Type": "application/json", "Accept": "application/json"},
                method="POST",
            )
            with urllib.request.urlopen(request, timeout=5) as accepted:
                assert accepted.status == 202
                assert b"access_token" not in accepted.read()
            try:
                urllib.request.urlopen(request, timeout=5)
            except urllib.error.HTTPError as replay:
                assert replay.code == 422, replay.code
            else:
                raise AssertionError("Consumed staff token was accepted twice")
            print("PASS: Staff email invite, HTTP acceptance and single-use replay denial.")
            break
    except (urllib.error.URLError, TimeoutError, KeyError, IndexError):
        pass
    time.sleep(1)
else:
    raise SystemExit("FAIL: Staff invitation SMTP or HTTP acceptance failed.")
PY
