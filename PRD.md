# PRD: Postfix Hardening for Receive-Only Mail Server

## Background

This package (`notifiable/receive-email`) configures Postfix as a receive-only mail server for Laravel applications. The `SetupPostfixCommand` currently writes a minimal Postfix configuration that lacks critical security, performance, and reliability settings. An independent review by three AI agents (claude-opus, codex-5.4, composer-2) identified 8 consensus issues that must be addressed.

The server is **receive-only** — it should never send, relay, or originate mail.

---

## Requirement 1: Message Size Limit

### Problem
No `message_size_limit` is configured in `main.cf`. Postfix's default allows arbitrarily large emails (~50MB). An attacker can send massive emails that exhaust RAM, disk, and CPU as each email is read into PHP memory via `php://stdin`, parsed by `php-mime-mail-parser`, stored to disk, and inserted into the database.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php:79-96` — the `configureMainConfigFile()` method only writes `myhostname`, `smtpd_recipient_restrictions`, and `local_recipient_maps`.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

In the `configureMainConfigFile()` method, after the existing `upsertLine` calls (line 94-95), add a new `upsertLine` call to append:

```
message_size_limit = 26214400
```

This is 25MB, which is generous for most use cases.

**File: `config/receive_email.php`**

Add a new config key:

```php
'message-size-limit' => 26214400,
```

With a docblock explaining this controls the Postfix `message_size_limit` parameter (in bytes). The setup command should read from this config value so users can tune it.

**File: `src/Console/Commands/SetupPostfixCommand.php`**

The `configureMainConfigFile()` method should read the config value:

```php
$messageSizeLimit = 'message_size_limit = ' . config('receive_email.message-size-limit', 26214400);
$this->upsertLine($mainConfig, $messageSizeLimit);
```

### Acceptance Criteria
- `message_size_limit` is written to `main.cf` during setup
- Default value is `26214400` (25MB)
- Value is configurable via `receive_email.message-size-limit` config key
- Existing tests still pass

---

## Requirement 2: Pipe Transport Concurrency Limit

### Problem
The pipe transport in `master.cf` has no `maxproc` limit (the 5th field is `-`, meaning unlimited). Under a spam flood, Postfix will fork unlimited concurrent PHP/artisan processes, each booting the full Laravel framework, opening a DB connection, and parsing MIME. This will exhaust RAM and crash the server.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php:119`:
```php
$deliveryMethod = "notifiable unix - n n - - pipe flags=F user=$user argv={$command}";
```

The 7th positional field (between the last `-` and `pipe`) is `-` which means inherit `default_process_limit` (100 by default).

### Required Changes

**File: `config/receive_email.php`**

Add a new config key:

```php
'pipe-concurrency' => 4,
```

With a docblock explaining this controls the maximum number of concurrent pipe processes Postfix will spawn for email delivery. It maps to the `maxproc` field in `master.cf`. Lower values protect the server under load; higher values increase throughput. Start with 2-4 and tune based on server resources.

**File: `src/Console/Commands/SetupPostfixCommand.php`**

In `configureMasterConfigFile()`, change line 119 to read the config value and interpolate it into the `maxproc` position:

```php
$concurrency = config('receive_email.pipe-concurrency', 4);
$deliveryMethod = "notifiable unix - n n - {$concurrency} pipe flags=F user=$user argv={$command}";
```

### Acceptance Criteria
- The `maxproc` field in the `notifiable` transport line is set to a numeric value (not `-`)
- Default value is `4`
- Value is configurable via `receive_email.pipe-concurrency` config key
- Existing tests still pass

---

## Requirement 3: Disable Outbound Delivery

### Problem
The server is intended to be receive-only, but Postfix's default configuration allows it to send and relay mail. If anything on the server (a compromised PHP process, a cron job, a misconfiguration) attempts to send mail through Postfix, it will succeed. The current `smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination` still permits relaying from `mynetworks` (which includes localhost).

### Current Code
`src/Console/Commands/SetupPostfixCommand.php:79-96` — no outbound transport restrictions are set.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

In the `configureMainConfigFile()` method, add `upsertLine` calls for:

```
default_transport = error
relay_transport = error
```

These tell Postfix to return a permanent error for any outbound delivery attempt, effectively making it impossible for the server to send or relay mail.

### Acceptance Criteria
- `default_transport = error` is written to `main.cf` during setup
- `relay_transport = error` is written to `main.cf` during setup
- These are always applied (not configurable) since the package is explicitly receive-only
- Existing tests still pass

---

## Requirement 4: TLS Configuration

### Problem
No TLS is configured. All inbound SMTP connections are plaintext, meaning email content is readable by any network observer in transit.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php` — no TLS parameters written anywhere.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

Add two new options to the command signature:

```php
protected $signature = 'notifiable:setup-postfix
    {domain : The domain where to receive emails from.}
    {--user= : The system user to run the pipe command as.}
    {--tls-cert= : Path to the TLS certificate file (PEM format).}
    {--tls-key= : Path to the TLS private key file (PEM format).}';
```

In `configureMainConfigFile()`, after the existing configuration, add a TLS configuration block:

```php
$tlsCert = $this->option('tls-cert');
$tlsKey = $this->option('tls-key');

if ($tlsCert && $tlsKey) {
    // Validate that the cert and key files exist
    if (!file_exists($tlsCert)) {
        throw new RuntimeException("TLS certificate file does not exist: {$tlsCert}");
    }
    if (!file_exists($tlsKey)) {
        throw new RuntimeException("TLS key file does not exist: {$tlsKey}");
    }

    $this->upsertLine($mainConfig, "smtpd_tls_cert_file = {$tlsCert}");
    $this->upsertLine($mainConfig, "smtpd_tls_key_file = {$tlsKey}");
    $this->upsertLine($mainConfig, 'smtpd_tls_security_level = may');
    $this->upsertLine($mainConfig, 'smtpd_tls_protocols = !SSLv2, !SSLv3, !TLSv1, !TLSv1.1');
    $this->upsertLine($mainConfig, 'smtpd_tls_loglevel = 1');

    // Disable outbound TLS since we don't send mail
    $this->upsertLine($mainConfig, 'smtp_tls_security_level = none');
} else {
    $this->warn('TLS is not configured. Inbound SMTP connections will be unencrypted.');
    $this->warn('Use --tls-cert and --tls-key to enable TLS.');
}
```

Key design decisions:
- Use `smtpd_tls_security_level = may` (opportunistic), NOT `encrypt` (mandatory). Mandatory TLS rejects mail from servers that don't support STARTTLS, which is still common.
- Disable SSLv2, SSLv3, TLSv1, and TLSv1.1 — only TLSv1.2+ allowed.
- Set `smtp_tls_security_level = none` since we never send outbound.
- If TLS options are not provided, print a warning but don't fail — TLS is recommended, not required.

### Acceptance Criteria
- Running with `--tls-cert` and `--tls-key` writes all 6 TLS parameters to `main.cf`
- Running without TLS options prints a warning but completes successfully
- Certificate and key file existence is validated before writing config
- Only `smtpd_tls_security_level = may` (opportunistic) is used, never `encrypt`
- Existing tests still pass

---

## Requirement 5: SPF/DKIM/DMARC Verification

### Problem
No email authentication verification is configured. The sender-based filters (`SenderDomainWhitelistFilter`, `SenderAddressBlacklistFilter`, etc.) operate on unverified `From:`/`Sender:` headers. Any attacker can trivially forge these headers, making the filters bypassable. The `sender` stored in the database has zero authenticity guarantee.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php` — no authentication milters or policy services configured.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

Add a new option to the command signature:

```php
{--with-spf : Install and configure SPF verification via policyd-spf.}
```

When `--with-spf` is passed, add a new method `configureSPF()` that:

1. Installs `postfix-policyd-spf-python`:
```php
$this->line((string) shell_exec('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-policyd-spf-python'));
```

2. Appends to `main.cf`:
```
policy-spf_time_limit = 3600s
```

3. Modifies `smtpd_recipient_restrictions` to include the SPF policy check at the end. The updated value should be:
```
smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, check_policy_service unix:private/policy-spf
```

Note: This means the `smtpd_recipient_restrictions` upsertLine in `configureMainConfigFile()` needs to be conditional — if `--with-spf` is used, append the `check_policy_service` clause.

4. Appends to `master.cf` (via a new `upsertLine` call on the master config):
```
policy-spf unix -  n  n  -  0  spawn user=policyd-spf argv=/usr/bin/policyd-spf
```

Call this method in `handle()` between `configureMasterConfigFile()` and `reloadPostfix()`, only when `--with-spf` is passed:

```php
if ($this->option('with-spf')) {
    $this->configureSPF($domain);
}
```

### Implementation Notes
- SPF is opt-in via `--with-spf` flag, not enabled by default, because it requires an additional system package
- DKIM and DMARC are out of scope for this PRD — they require more complex setup (OpenDKIM/rspamd) that is better documented than automated. Add a note in the command output recommending rspamd for DKIM/DMARC.
- When `--with-spf` is NOT passed, print an informational message: "For production use, consider --with-spf for SPF verification, and rspamd for DKIM/DMARC."

### Acceptance Criteria
- `--with-spf` installs `postfix-policyd-spf-python` and configures both `main.cf` and `master.cf`
- SPF check is added to `smtpd_recipient_restrictions`
- Without `--with-spf`, an informational message is printed recommending it
- Existing tests still pass

---

## Requirement 6: Rate Limiting and Abuse Prevention

### Problem
No rate limiting or abuse prevention parameters are configured. A single source can open unlimited connections and send unlimited messages, enabling flood attacks that overwhelm the pipe transport and database.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php:79-96` — no rate limiting parameters set.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

In the `configureMainConfigFile()` method, add `upsertLine` calls for:

```
smtpd_client_connection_rate_limit = 30
smtpd_client_message_rate_limit = 60
smtpd_client_recipient_rate_limit = 120
smtpd_error_sleep_time = 1s
smtpd_soft_error_limit = 5
smtpd_hard_error_limit = 10
```

Also add data-level protection against pipelining attacks:

```
smtpd_data_restrictions = reject_unauth_pipelining
```

And a timeout to prevent slowloris-style SMTP attacks:

```
smtpd_timeout = 120s
```

And shorter queue lifetimes since a receive-only server shouldn't retry for 5 days (the default):

```
maximal_queue_lifetime = 1d
bounce_queue_lifetime = 1d
```

These values are NOT made configurable — they are sensible defaults for a receive-only server and don't need per-deployment tuning in the same way that message size or concurrency do.

### What Each Parameter Does (for the implementor)
- `smtpd_client_connection_rate_limit = 30` — Max 30 connections per minute from a single IP. Primary defense against connection floods. Default is 0 (unlimited).
- `smtpd_client_message_rate_limit = 60` — Max 60 messages per minute from a single IP.
- `smtpd_client_recipient_rate_limit = 120` — Max 120 recipients per minute from a single IP.
- `smtpd_error_sleep_time = 1s` — Delay after each SMTP error. Slows brute-force probing.
- `smtpd_soft_error_limit = 5` — After 5 soft errors, start rejecting.
- `smtpd_hard_error_limit = 10` — After 10 hard errors, disconnect the client.
- `smtpd_data_restrictions = reject_unauth_pipelining` — Blocks clients that send commands before receiving the server's response (common spam technique).
- `smtpd_timeout = 120s` — Disconnect clients that idle for 2 minutes.
- `maximal_queue_lifetime = 1d` — Don't retry undeliverable messages for more than 1 day.
- `bounce_queue_lifetime = 1d` — Don't retry bounce messages for more than 1 day.

### Acceptance Criteria
- All 10 parameters above are written to `main.cf` during setup
- These are always applied (not configurable) — they are sensible security defaults
- Existing tests still pass

---

## Requirement 7: HELO/Sender/Banner Restrictions

### Problem
No HELO validation, no sender domain validation, VRFY command is enabled, and the Postfix version is exposed in the SMTP banner. This allows: connecting without identifying, claiming to be any sender domain, probing for valid users via VRFY, and fingerprinting the server version.

### Current Code
`src/Console/Commands/SetupPostfixCommand.php:92`:
```php
$smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination';
```

No other restriction classes configured.

### Required Changes

**File: `src/Console/Commands/SetupPostfixCommand.php`**

In the `configureMainConfigFile()` method, add `upsertLine` calls for:

**HELO restrictions:**
```
smtpd_helo_required = yes
smtpd_helo_restrictions = reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname
```

Do NOT add `reject_unknown_helo_hostname` — it's too aggressive and rejects legitimate mail from misconfigured servers.

**Sender restrictions:**
```
smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain
```

**Disable VRFY (user enumeration):**
```
disable_vrfy_command = yes
```

**Hide version from banner:**
```
smtpd_banner = $myhostname ESMTP
```

Note: `$myhostname` here is a Postfix variable, not a PHP variable. It must be written literally to the config file. Make sure it is not interpolated by PHP. Use single quotes in PHP or escape the `$`.

**Expand recipient restrictions** — change the existing line from:
```
smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination
```
to:
```
smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination
```

This adds `reject_non_fqdn_recipient` and `reject_unknown_recipient_domain` before `reject_unauth_destination` to reject obviously invalid recipients at the SMTP level, before the email reaches the PHP pipeline.

### Implementation Note on `upsertLine`
The existing `upsertLine()` method (line 183-197) checks if the file content already contains the exact line. For `smtpd_recipient_restrictions`, this means the old value and new value will differ, and a second run will append a duplicate. The `smtpd_recipient_restrictions` line should use `editLine()` (like `myhostname` does) with a regex match, falling back to `upsertLine` if not found. This is the same pattern already used for `myhostname` on line 86.

Specifically, change the `smtpd_recipient_restrictions` handling from:
```php
$smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination';
$this->upsertLine($mainConfig, $smtpdRecipientRestrictions);
```
to:
```php
$smtpdRecipientRestrictions = 'smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination';
$edited = $this->editLine($mainConfig, '/^smtpd_recipient_restrictions = (.*)$/m', $smtpdRecipientRestrictions);
if ($edited === null) {
    $this->upsertLine($mainConfig, $smtpdRecipientRestrictions);
}
```

This ensures re-running the setup command updates the line rather than duplicating it.

### Acceptance Criteria
- All 6 parameters (`smtpd_helo_required`, `smtpd_helo_restrictions`, `smtpd_sender_restrictions`, `disable_vrfy_command`, `smtpd_banner`, expanded `smtpd_recipient_restrictions`) are written to `main.cf`
- `smtpd_banner` uses `$myhostname ESMTP` (Postfix variable, not PHP-interpolated)
- `smtpd_recipient_restrictions` is updated via `editLine` to avoid duplicates on re-run
- Existing tests still pass

---

## Requirement 8: Fix StoreAndDispatch File Storage Ordering

### Problem
In `StoreAndDispatch.php`, the DB transaction is committed BEFORE the raw email file is written to disk. If `store()` fails (disk full, permissions error, storage driver issue), the database contains a record pointing to a file that doesn't exist — an orphaned record.

### Current Code
`src/StoreAndDispatch.php:15-43`:
```php
public function handle(ParsedMailContract $parsedMail): void
{
    DB::beginTransaction();

    try {
        $sender = Sender::query()->updateOrCreate(
            ['address' => mb_strtolower($parsedMail->sender()->address)],
            ['display' => $parsedMail->sender()->display],
        );

        $email = $sender->emails()->create([
            'message_id' => $parsedMail->id(),
            'sent_at' => $parsedMail->date(),
        ]);

        DB::commit();
    } catch (Exception $e) {
        DB::rollBack();
        throw $e;
    }

    $parsedMail->store($email->path());   // <-- If this fails, DB record is orphaned

    event(new EmailReceived($email));
}
```

### Required Changes

**File: `src/StoreAndDispatch.php`**

Move the `store()` call inside the try block, before `DB::commit()`. If `store()` fails, the transaction rolls back and no orphaned record is created.

The new implementation should be:

```php
public function handle(ParsedMailContract $parsedMail): void
{
    DB::beginTransaction();

    try {
        /** @var Sender $sender */
        $sender = Sender::query()->updateOrCreate(
            ['address' => mb_strtolower($parsedMail->sender()->address)],
            ['display' => $parsedMail->sender()->display],
        );

        /** @var Email $email */
        $email = $sender->emails()->create([
            'message_id' => $parsedMail->id(),
            'sent_at' => $parsedMail->date(),
        ]);

        $parsedMail->store($email->path());

        DB::commit();
    } catch (Exception $e) {
        DB::rollBack();

        throw $e;
    }

    event(new EmailReceived($email));
}
```

Note: If the DB commit succeeds but then the process crashes before `event()`, that is acceptable — the email is stored and can be discovered. The critical invariant is: **a DB record must never exist without its corresponding file**.

Also note: if the DB transaction rolls back after `store()` succeeds, there will be an orphaned file on disk with no DB record. This is the lesser evil — an orphaned file wastes disk space but doesn't cause application errors (no record references it). To handle this edge case cleanly, add a catch that attempts to clean up the file:

```php
public function handle(ParsedMailContract $parsedMail): void
{
    DB::beginTransaction();

    try {
        /** @var Sender $sender */
        $sender = Sender::query()->updateOrCreate(
            ['address' => mb_strtolower($parsedMail->sender()->address)],
            ['display' => $parsedMail->sender()->display],
        );

        /** @var Email $email */
        $email = $sender->emails()->create([
            'message_id' => $parsedMail->id(),
            'sent_at' => $parsedMail->date(),
        ]);

        $parsedMail->store($email->path());

        DB::commit();
    } catch (Exception $e) {
        DB::rollBack();

        if (isset($email)) {
            storage()->delete($email->path());
        }

        throw $e;
    }

    event(new EmailReceived($email));
}
```

### Existing Tests
`tests/Unit/StoreAndDispatchTest.php` — review and update this test file to verify:
1. The file is stored before DB commit
2. If `store()` throws, the DB transaction is rolled back (no orphaned records)
3. If DB commit fails after `store()`, the file is cleaned up

### Acceptance Criteria
- `store()` is called inside the DB transaction, before `commit()`
- If `store()` throws, the DB rolls back and no record exists
- If DB commit fails, the orphaned file is deleted in the catch block
- The `EmailReceived` event is still dispatched after a successful commit
- Existing tests updated and passing

---

## Out of Scope

The following items were identified in the review but are explicitly NOT part of this PRD:

- **Wrong exit code (`EX_NOHOST` for filtered mail)** — P0 item, separate PRD
- **`user=root` default when running with `sudo`** — P0 item, separate PRD
- **Envelope metadata not passed to pipe** — P1, separate PRD (architectural change)
- **`content_filter` on `smtp inet` vs `main.cf`** — P2, separate PRD
- **`flags=F` mbox separator** — P2, separate investigation
- **Postscreen configuration** — recommended but optional, separate PRD
- **rspamd integration** — recommended for DKIM/DMARC, separate PRD
- **PHP-level stdin size guard** — defense-in-depth, separate PRD
- **Queue worker architecture** — long-term architectural change, separate PRD

---

## Testing Strategy

All changes to `SetupPostfixCommand` modify Postfix config file writes. These are integration-level behaviors that depend on the Postfix config files existing. The existing test patterns in the codebase should be followed.

For `StoreAndDispatch`, the existing `tests/Unit/StoreAndDispatchTest.php` must be updated with new test cases per Requirement 8's acceptance criteria.

For config changes, verify the new keys exist in `config/receive_email.php` with correct defaults.
