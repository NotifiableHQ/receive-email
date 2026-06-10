# Changelog

All notable changes to `notifiablehq/receive-email` are documented here.

## 1.0.0 (Unreleased) — Receive-only hardening wave

This release hardens the package around its core invariant: the server accepts inbound mail and originates nothing — no sending, no relaying, no bounces. The decisions are recorded in `docs/adr/`; see the README's [Upgrading to v1](README.md#upgrading-to-v1) section for migration steps.

### Changed (breaking)

- The built-in sender lists (`sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, `sender-address-blacklist`) now match the **Envelope Sender** (SMTP `MAIL FROM`) instead of the Header Sender, and are enforced at SMTP time as Postfix `check_sender_access` maps compiled by the new `notifiable:sync-postfix` command. The built-in filter classes are no longer evaluated pipe-time. (ADR-0001)
- SPF verification is now configured by default during setup; the `--with-spf` flag is replaced by a `--without-spf` opt-out. On Ubuntu releases that no longer ship `postfix-policyd-spf-python`, setup falls back to `spf-engine`.
- Pipe exit codes: filtered and malformed mail now exits `0` (Discard — `EmailRejected` is dispatched for filtered mail; no bounce is requested) instead of `EX_NOHOST`; transient failures exit `75` (`EX_TEMPFAIL`) so Postfix keeps the message queued. (ADR-0001)
- Setup now requires root on Ubuntu 24.04+ (preflight check; `--force` skips the OS check) and refuses to configure the pipe to run as `root`. The pipe user defaults to `$SUDO_USER`, then the current user.
- Queue lifetimes: `maximal_queue_lifetime = 5d` so tempfailed mail survives a multi-day incident; `bounce_queue_lifetime = 0` since bounces can never be delivered.

### Added

- `notifiable:sync-postfix` — compiles the sender lists into Postfix access maps and owns `smtpd_sender_restrictions`; idempotent, reloads Postfix only on change, intended for deploy hooks. (ADR-0001)
- `notifiable:import-mail-log` — a scheduled importer that tails the Postfix mail log from a persisted offset and dispatches `SmtpRejectionObserved` events for SMTP-time rejections (sender lists, SPF, HELO, rate limits, postscreen), with log-rotation detection. New config keys: `mail-log-path`, `mail-log-offset-path`. (ADR-0002)
- postscreen as the pre-smtpd gatekeeper: zombies that talk before the SMTP greeting are dropped before consuming an smtpd process; tlsproxy keeps inbound STARTTLS working behind it.
- Setup postflight verification: `postfix check` plus a check that `mydestination` includes the receiving domain, before Postfix is reloaded.
- The pipe command buffers stdin and tempfails input exceeding `message-size-limit`, guarding against a hand-edited `main.cf`.
- Domain glossary (`CONTEXT.md`) and architecture decision records (`docs/adr/`).

### Fixed

- Rejected mail no longer asks Postfix for a bounce it can never deliver, eliminating double-bounce queue churn.
- TLS protocol selection modernized to `smtpd_tls_protocols = >=TLSv1.2` (Postfix 3.6+ syntax).
- Whitelist mode no longer rejects the null envelope sender (`MAIL FROM:<>`): remote bounces and DSNs addressed to the receiving domain are accepted, as RFC 5321 requires (pre-release review finding).
- `notifiable:sync-postfix` can no longer report "already up to date" over a stale access-map index: the map text and its `.db` are staged and renamed into place only after `postmap` succeeds, so a failed build is retried on the next run (pre-release review finding).
- Setup now fails loudly when `systemctl reload postfix` fails instead of exiting 0 with the verified configuration never loaded (pre-release review finding).
- Mail-log importer durability: offsets are persisted atomically after every dispatched rejection (previously once per run, non-atomically), so a mid-run failure replays at most the single in-flight line. The documented guarantee is now at-least-once with a minimal duplicate window (pre-release review finding).
- The mail-log importer now recognizes postscreen enforce-mode drops (PREGREET, HANGUP, DNSBL) and classifies postscreen connection-count rejects as rate limits; previously these were invisible (pre-release review finding).
- First importer run on an existing server now fast-forwards to the end of the log instead of dispatching the entire history; `--from-beginning` opts into the backlog (pre-release review finding).
