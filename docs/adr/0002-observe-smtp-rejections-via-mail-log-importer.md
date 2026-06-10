# Observe SMTP-time rejections via a scheduled mail-log importer

SMTP-time Rejection happens inside Postfix, so the application never sees the rejected mail — but the application needs to observe rejections, not just the MTA log. We decided to keep enforcement in native Postfix access maps and add a scheduled artisan importer that tails the mail log from a saved offset, parses reject lines, and dispatches events/records into the application. This keeps Laravel out of the per-message SMTP hot path and — unlike any policy-service approach — observes *every* rejection class: envelope lists, SPF, HELO violations, rate limits, and postscreen drops.

## Considered Options

- Laravel policy daemon (`check_policy_service` → long-running artisan process): real-time and single-source-of-truth, but puts Laravel in the SMTP transaction (daemon down → all mail deferred), adds a systemd unit to deploy lifecycles, and still only sees the rejections it is consulted on — SPF/HELO/postscreen remain invisible.
- Maps-plus-observer hybrid (DUNNO-only policy service): real-time without enforcement coupling, but two mechanisms to maintain and still blind to pre-policy-stage rejections.

## Consequences

- Visibility is near-real-time (scheduler cadence), not synchronous.
- The importer must tolerate Postfix log format drift and log rotation, and the app user needs read access to the mail log (e.g. membership in the `adm` group on Ubuntu).
