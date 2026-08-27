#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${SIMPLELAB_BASE_URL:-http://127.0.0.1:8080}"
TOKEN="${SIMPLELAB_API_TOKEN:-test_token_1234567890}"
fail(){ echo "FAIL: $*" >&2; exit 1; }
pass(){ echo "PASS: $*"; }
code="$(curl -sS -o /tmp/simplelab-health.json -w '%{http_code}' "$BASE_URL/api/v1/health")"
[[ "$code" == 200 ]] || fail "health returned HTTP $code"
jq -e '.status == "ok"' /tmp/simplelab-health.json >/dev/null || fail "invalid health response"
pass "health"
code="$(curl -sS -o /tmp/simplelab-unauth.json -w '%{http_code}' -X POST "$BASE_URL/api/v1/onboarding" -H 'Content-Type: application/json' -d '{"machine_id":"machine-noauth-0001"}')"
[[ "$code" == 401 ]] || fail "missing token should return 401, got $code"
pass "authentication"
onboard(){ local machine_id="$1" out="$2"; curl -sS -o "$out" -w '%{http_code}' -X POST "$BASE_URL/api/v1/onboarding" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "{\"machine_id\":\"$machine_id\",\"ip\":\"10.0.10.25\",\"mac\":\"BC:24:11:AA:BB:CC\",\"os\":\"ubuntu\",\"os_version\":\"24.04\",\"architecture\":\"x86_64\"}"; }
code1="$(onboard 'test-machine-00000001' /tmp/simplelab-vm1.json)"
[[ "$code1" == 201 || "$code1" == 200 ]] || fail "first onboarding HTTP $code1"
host1="$(jq -r '.hostname' /tmp/simplelab-vm1.json)"; [[ -n "$host1" && "$host1" != null ]] || fail "first hostname missing"; pass "first onboarding: $host1"
code2="$(onboard 'test-machine-00000001' /tmp/simplelab-vm1-again.json)"; [[ "$code2" == 200 ]] || fail "repeat onboarding HTTP $code2"
host1b="$(jq -r '.hostname' /tmp/simplelab-vm1-again.json)"; [[ "$host1" == "$host1b" ]] || fail "idempotency failed: $host1 != $host1b"; jq -e '.existing == true' /tmp/simplelab-vm1-again.json >/dev/null || fail "repeat onboarding not marked existing"; pass "idempotent onboarding"
code3="$(onboard 'test-machine-00000002' /tmp/simplelab-vm2.json)"; [[ "$code3" == 201 || "$code3" == 200 ]] || fail "second VM onboarding HTTP $code3"
host2="$(jq -r '.hostname' /tmp/simplelab-vm2.json)"; [[ "$host2" != "$host1" ]] || fail "duplicate hostname assigned"; pass "second VM unique hostname: $host2"
echo "All API regression tests passed."
