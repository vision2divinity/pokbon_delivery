#!/usr/bin/env bash
# Keep the development tunnel answering.
#
# cloudflared has twice stayed alive as a process while no longer serving, so
# "is it running" is the wrong question — the only question that matters is
# whether a request from the internet reaches the API. A dispatcher who clicks
# "send to riders" and gets an error page cannot tell whether the fault is
# their order, their internet, or a laptop in another building, and a rider
# loses an offer to a 45-second window that expired during a flap.
#
# This is a development stopgap and should be deleted the day the API moves to
# a VPS. It is deliberately noisy in its log so the flapping stays visible
# rather than being quietly papered over.
#
#   bash ops/tunnel-watchdog.sh
set -u

URL="https://delivery-dev.pokbongroup.com/health"
CFG="ops/cloudflared.yml"
EXE="/c/Program Files (x86)/cloudflared/cloudflared.exe"
INTERVAL=15
FAILS_BEFORE_RESTART=2

fails=0
restarts=0

log() { printf '%s  %s\n' "$(date '+%H:%M:%S')" "$1"; }

log "watching $URL every ${INTERVAL}s"

while true; do
  code=$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$URL" || echo 000)

  if [ "$code" = "200" ]; then
    if [ "$fails" -gt 0 ]; then
      log "recovered after $fails failed check(s)"
    fi
    fails=0
  else
    fails=$((fails + 1))
    log "check failed (HTTP $code), $fails in a row"

    if [ "$fails" -ge "$FAILS_BEFORE_RESTART" ]; then
      # Only the tunnel is restarted. The API is a separate process and
      # restarting it would drop a rider's in-flight request for no reason.
      log "restarting cloudflared"
      powershell -NoProfile -Command "Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force" >/dev/null 2>&1
      sleep 2
      "$EXE" --config "$CFG" tunnel run >/dev/null 2>&1 &
      restarts=$((restarts + 1))
      log "restarted (#$restarts); waiting for it to come up"
      sleep 12
      fails=0
    fi
  fi

  sleep "$INTERVAL"
done
