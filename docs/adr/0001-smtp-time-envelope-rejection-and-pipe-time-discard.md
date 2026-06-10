# SMTP-time envelope rejection for built-in lists; silent discard for pipe-time rejections

The package is receive-only (`default_transport = error`), so it can never deliver a bounce — yet rejected mail was exiting with `EX_NOHOST`, asking Postfix to bounce, producing undeliverable double-bounces and queue churn. We decided to reject as early as possible and never generate a bounce at all: the built-in whitelist/blacklist lists are redefined to **Envelope Sender** semantics and compiled into Postfix `check_sender_access` maps, so matching mail is refused with a 5xx during the SMTP transaction and the *sending* server owns notification. Custom **Pipe-time Filters** keep full parsed-header access but run after acceptance, so their rejections are **silently discarded** (`EX_OK` after dispatching `EmailRejected`) — bouncing post-acceptance is backscatter, and with the outbound lock it is impossible anyway.

## Considered Options

- Header-based rejection via PCRE `header_checks` at DATA time — preserves the old header semantics in-session, but whitelists need fragile negative-lookahead regexes and the matched header is trivially forgeable.
- Envelope-only filtering everywhere — simplest, but kills the custom PHP filter extension point and surprises users whose mail (e.g. ESP-sent: `From: alerts@stripe.com`, envelope `bounces@em5678.stripe.com`) diverges between header and envelope.
- Keep `EX_NOHOST` — sender notification in principle, but in practice the bounce dies against the outbound lock; pure cost.

## Consequences

- The `sender-*-whitelist/blacklist` config keys change meaning from Header Sender to Envelope Sender; this must be called out loudly in docs/upgrade notes (the Stripe scenario above can change which mail is accepted).
- A sync mechanism must regenerate and `postmap` the access maps when config lists change; the lists are static config, so DB-driven dynamic lists are out of scope for this layer.
- Rejected mail never reaches Laravel, so `EmailRejected` only fires for pipe-time discards; SMTP-time rejections are surfaced to the application by the mail-log importer (see ADR-0002).
- `--with-spf` becomes more important: SPF is what makes the Envelope Sender trustworthy enough to filter on.
