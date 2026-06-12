# Receive Email
Let your Laravel app receive emails.

This package turns a server into a receive-only mail server: Postfix accepts inbound mail and pipes it into your Laravel app, which filters, stores, and dispatches events. The server never sends, relays, or bounces mail.

## Requirements

| Layer | Requirement |
|-------|-------------|
| Your application (composer) | PHP 8.2+, Laravel 12 or 13, the `mailparse` PHP extension |
| The mail server (deployment platform) | Ubuntu 24.04+, which ships PHP 8.3 and Postfix 3.8 |

The composer constraints are deliberately wider than the deployment platform: your app can depend on this package anywhere PHP 8.2+ runs, but the server that receives mail must be Ubuntu 24.04+. The setup command verifies it is running as root on Ubuntu 24.04+ before changing anything; pass `--force` to attempt setup on other Debian-like systems.

## Installation
```bash
composer require notifiablehq/receive-email
```

## Usage

### 1. Publish Config and Migrations

Publish the configuration and migration files:

```bash
php artisan vendor:publish --provider="Notifiable\\ReceiveEmail\\ReceiveEmailServiceProvider" --tag=receive-email
```
Then run the migrations:

```bash
php artisan migrate
```

### 2. Listen for Incoming Emails

Every delivery the package stores is announced by exactly one of two events, and the split is a contract:

- **`Notifiable\ReceiveEmail\Events\EmailReceived`** — the message parsed successfully. The `Email` row carries the envelope columns *and* the header enrichment (`message_id`, `sent_at`, the `sender` relation, `parsed_at`), and `parsedMail()` gives you the parsed headers and body. This event never fires for Malformed Mail.
- **`Notifiable\ReceiveEmail\Events\MalformedEmailReceived`** — the message is Malformed Mail: accepted mail whose headers cannot be parsed, or whose parsed values cannot be stored faithfully (an oversized `Message-ID`, a `Date` outside the storable timestamp range). It is kept, never lost — the raw message and an envelope-only row are stored — but every enrichment field is `null`, so listeners may rely only on the envelope columns and the raw file. Calling `parsedMail()` still returns a parser over the raw message, but accessors that need a header the message lacks throw `MalformedMailException`; for unstorable-value Malformed Mail the accessors succeed — the value simply did not fit its column.

Both events carry the `Email` model as `$event->email` (see [The Email Row](#3-the-email-row)). Mail rejected by a Pipe-time Filter is never stored and dispatches `EmailRejected` instead — see [Rejected and Failed Mail](#rejected-and-failed-mail).

#### Create the Listener

Generate a listener class:

```bash
php artisan make:listener HandleIncomingEmail
```

Then implement the `handle` method:

```php
namespace App\Listeners;

use Notifiable\ReceiveEmail\Events\EmailReceived;

class HandleIncomingEmail
{
    public function handle(EmailReceived $event): void
    {
        $email = $event->email;
        
        \Log::info('Received email with subject: ' . $email->parsedMail()->subject());
    }
}
```

### 3. The Email Row

Each accepted delivery stores exactly one row, identified by its ULID. Rows are message-scoped: a delivery to several recipients is still one row. The columns come in two layers.

**Envelope columns** — recorded from the SMTP envelope at delivery time, on every row:

| Column | Type | Meaning |
|--------|------|---------|
| `envelope_sender` | `string\|null` | The Envelope Sender (SMTP `MAIL FROM`) — the trusted identity, the one SPF verifies. `null` means the null Envelope Sender (`MAIL FROM:<>`: remote bounces and delivery status notifications), never a placeholder address. |
| `envelope_recipients` | `array` | Every Envelope Recipient (SMTP `RCPT TO`) of the delivery — the actual delivery targets, independent of the `To:`/`Cc:` headers. |
| `client_address` | `string\|null` | The IP address of the client that delivered the message. |
| `queue_id` | `string\|null` | The Postfix queue ID of the delivery. |

**Enrichment columns** — extracted from the message headers after the row exists; best-effort and nullable. All of them are `null` for Malformed Mail:

| Column | Type | Meaning |
|--------|------|---------|
| `message_id` | `string\|null` | The `Message-ID` header. Indexed but **not unique** — see below. |
| `sender` (relation) | `Sender\|null` | The Header Sender (`Sender:` header, falling back to `From:`) — the display identity a human sees in a mail client, and trivially forgeable. The trusted identity is `envelope_sender`. |
| `sent_at` | `CarbonImmutable\|null` | The `Date` header. |
| `parsed_at` | `CarbonImmutable\|null` | When header parsing succeeded. `parsed_at === null` on a stored row *is* Malformed Mail. |

Two consequences worth internalizing:

- **The ULID is the identity; `Message-ID` is annotation.** Message-IDs are chosen by the sending system and legitimately duplicate: the same newsletter sent to two of your addresses arrives as two deliveries sharing one `Message-ID`, and stores as two rows. Deduplication, if your application wants it, is application policy — the envelope columns give you the context to decide with.
- **Header recipients and Envelope Recipients differ.** `parsedMail()->recipients()` reads the `To:`/`Cc:`/`Bcc:` headers — what a human sees; `envelope_recipients` records where the message was actually delivered. A message BCC'd to your domain reaches it via `RCPT TO` while its headers never mention it.

The parsed message itself is available through `parsedMail()`, which re-reads the raw file from the storage disk on every call (see [Raw message storage](#raw-message-storage)):

```php
/** @var \Notifiable\ReceiveEmail\Contracts\ParsedMailContract $mail */
$mail = $email->parsedMail();

$subject = $mail->subject();
$textBody = $mail->text();
$htmlBody = $mail->html();
$recipients = $mail->recipients();
```

### 4. Filter Incoming Mail (optional)

Mail can be turned away at two moments, and the package gives you a tool for each:

- **At SMTP time, before acceptance** — the sender list config keys are compiled into Postfix access maps keyed on the Envelope Sender (the SMTP `MAIL FROM` address). Matching mail is refused with a 5xx response and never reaches your application. See [Envelope Sender filtering](#envelope-sender-filtering).
- **At pipe time, after acceptance** — custom filter classes run inside your app with full parsed-header access. Register them in the `email-filters` config list; each must implement `EmailFilterContract`:

```php
use Notifiable\ReceiveEmail\Contracts\EmailFilterContract;
use Notifiable\ReceiveEmail\Contracts\ParsedMailContract;

class RejectNoReplyFilter implements EmailFilterContract
{
    public function filter(ParsedMailContract $parsedMail): bool
    {
        // Return true to accept, false to reject.
        return ! str_starts_with($parsedMail->sender()->address, 'no-reply@');
    }
}
```

Note that `sender()` here is the Header Sender — the forgeable display identity. The trusted Envelope Sender is filtered at SMTP time by the lists above.

When a Pipe-time Filter rejects a message it is Discarded — dropped without ever generating a bounce — and `Notifiable\ReceiveEmail\Events\EmailRejected` is dispatched so your application retains visibility. Filters run on parseable mail before anything is stored, so a rejected message leaves no row and no file. The rejection verdict always wins: a filter only reads the headers it needs, so when the rest of the message turns out unparseable the full `Mail` payload cannot be built — `EmailRejected` still fires, with `$event->mail` `null`. See [Rejected and Failed Mail](#rejected-and-failed-mail).

Pipe-time Filters never see Malformed Mail: a filter that reads a header the message lacks throws, and the message falls through to be kept and announced as `MalformedEmailReceived`. A custom filter is therefore not a barrier against deliberately malformed input — applications that care must also listen for that event.

Both tools key on sender identity — the Envelope Sender at SMTP time, parsed headers at pipe time. There is deliberately no recipient-level filtering: every recipient at the receiving domain is accepted, and recipient policy belongs in your application — see [The Catch-all Recipient Contract](#the-catch-all-recipient-contract).

## Forge Deployment
1. Add this to your recipes, you can name it `Install Mailparse`. Make sure the user is `root`.
```bash
apt-get update
apt-get install -y php-cli php-mailparse
```

2. If you already have an existing server, run this recipe on that server. 
Otherwise, create a new server and make sure to select this recipe as a `Post-Provision Recipe`. 
You'll have to show `Advance Settings` to select this.

3. Once you have the server ready, open up `Port 25`, add your site, and deploy your Laravel app.

4. Activate an SSL certificate for your site in Forge (Sites > your site > SSL). Forge uses Let's Encrypt and places certs at:
    - Certificate: `/etc/nginx/ssl/your-application-domain.com/server.crt`
    - Private key: `/etc/nginx/ssl/your-application-domain.com/server.key`

5. SSH into your Forge server and go to your site directory. Then run the setup command with `sudo` (see [Requirements](#requirements) — the command verifies it is running as root on a supported platform before changing anything):
```bash
sudo php artisan notifiable:setup-postfix domain-that-receives-email.com \
    --tls-cert=/etc/nginx/ssl/your-application-domain.com/server.crt \
    --tls-key=/etc/nginx/ssl/your-application-domain.com/server.key
```

By default, setup configures SPF verification for inbound mail — SPF is what makes the Envelope Sender trustworthy enough to filter on. It also compiles your configured sender lists into Postfix access maps, verifies the resulting configuration (`postfix check` and that `mydestination` includes your receiving domain), and reloads Postfix.

**Available options:**

| Option | Description |
|--------|-------------|
| `--user=forge` | The system user Postfix runs the pipe command as. Defaults to `$SUDO_USER` when run with `sudo`, otherwise the current user. Setup aborts if the resolved user is `root`. |
| `--tls-cert=` | Path to the TLS certificate file (PEM format). Enables opportunistic TLS for inbound SMTP. |
| `--tls-key=` | Path to the TLS private key file (PEM format). Must be provided together with `--tls-cert`. |
| `--without-spf` | Skips SPF verification setup. By default, setup installs `postfix-policyd-spf-python` (or `spf-engine` on newer releases) and configures SPF verification for inbound mail. |
| `--force` | Skips the Ubuntu 24.04+ operating system check, for other Debian-like systems. |

6. Add the following DNS records to your domain:

    | Type | Host                        | Value                 |
    |------|-----------------------------|-----------------------|
    | A    | your-application-domain.com | your.forge.ip.address |
 
    | Type | Host                           | Value                          | Priority |
    |------|--------------------------------|--------------------------------|----------|
    | MX   | domain-that-receives-email.com | your-application-domain.com    | 10       |

    | Type | Host                           | Value                          |
    |------|--------------------------------|--------------------------------|
    | TXT  | domain-that-receives-email.com | v=spf1 mx -all                |

    The SPF TXT record tells other mail servers that only your MX host is authorized to send mail for this domain. Even though this is a receive-only server, publishing an SPF record prevents others from spoofing your domain.

7. If you use the sender list config keys, re-sync the Postfix access maps whenever the lists change — the natural place is your Forge deploy script:

```bash
sudo php artisan notifiable:sync-postfix --isolated
```

The command is idempotent and only reloads Postfix when something actually changed, so it is safe to run on every deploy. `--isolated` makes overlapping runs take turns: the command implements Laravel's [`Isolatable`](https://laravel.com/docs/artisan#isolatable-commands) contract, and two deploys syncing concurrently would otherwise race on the map staging files. See [Envelope Sender filtering](#envelope-sender-filtering) for what it does.

8. To surface mail rejected during the SMTP transaction (sender lists, SPF, rate limits) inside your application, set up the mail-log importer — see [Observing SMTP-time Rejections](#observing-smtp-time-rejections).

## Configuration

After publishing the config file, you can tune the following settings in `config/receive_email.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `message-size-limit` | `26214400` (25MB) | Maximum inbound email size in bytes. Written to Postfix's `message_size_limit`, and enforced again by the pipe command as a guard. |
| `pipe-concurrency` | `4` | Maximum concurrent pipe processes. Maps to `maxproc` in `master.cf`. |
| `storage-disk` | `local` | Filesystem disk for storing raw email files. See [Raw message storage](#raw-message-storage). |
| `email-table` | `emails` | Table name for the Email model. |
| `sender-table` | `senders` | Table name for the Sender model. |
| `email-filters` | `[]` | Custom Pipe-time Filter classes, applied in order after a message is accepted and piped in. |

To apply changes to `message-size-limit` or `pipe-concurrency`, re-run the setup command.

### Raw message storage

Accepted mail is stored raw — byte-for-byte as received — on the disk named by `storage-disk`, and every read parses it back from that disk. Any Laravel filesystem disk works: the read path streams the file rather than assuming a local path, so S3 (via `league/flysystem-aws-s3-v3` in your application) is a supported configuration.

Storage failure semantics are the same on every disk: a write that fails (returns `false` or throws) tempfails the delivery before any row is committed, so Postfix keeps the message queued and retries; reading a stored message whose raw file has gone missing or unreadable throws `Notifiable\ReceiveEmail\Exceptions\FailedToReadException`.

Remote disks carry two operational caveats:

- **The upload is synchronous, inside Postfix's delivery pipe.** The raw message is stored before the row commits, so upload latency sits in the delivery path: each delivery holds one of the pipe's `pipe-concurrency` (default 4) process slots until the upload completes, and a slow or flaky uplink throttles delivery throughput.
- **Every `parsedMail()` call re-downloads the raw message.** The package keeps no local copy and no parsed cache; each read streams the full message from the remote disk again. Listeners that read the same message repeatedly pay one download per call — read once and keep what you need.

### Envelope Sender filtering

The `sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, and `sender-address-blacklist` config lists are enforced at SMTP time as Postfix access maps keyed on the Envelope Sender — the SMTP `MAIL FROM` address, the identity SPF verifies. The setup command syncs them once; whenever the lists change, re-sync them (e.g. from a deploy hook, see [Forge Deployment](#forge-deployment)):

```bash
sudo php artisan notifiable:sync-postfix --isolated
```

When any whitelist has entries, the server rejects every Envelope Sender not present in a whitelist. With only blacklists, listed Envelope Senders are rejected and everyone else is accepted; a blacklisted entry wins even when a whitelist would also match it. Rejected mail is refused during the SMTP transaction with a 5xx response — the sending server is responsible for notifying its sender, and the rejection never reaches your application code (the [mail-log importer](#observing-smtp-time-rejections) closes that visibility gap).

Domain list entries match subdomains too: Postfix's default [`parent_domain_matches_subdomains`](https://www.postfix.org/postconf.5.html#parent_domain_matches_subdomains) setting includes the sender access maps, so a `sender-domain-blacklist` entry `example.com` also rejects mail whose Envelope Sender is `user@sub.example.com`, and a whitelisted `example.com` likewise accepts mail from all of its subdomains. There is no way to match a domain *without* its subdomains under this default; listing a subdomain (`sub.example.com`) scopes the match to that subdomain and anything beneath it. Address entries (`user@example.com`) are always exact.

Whitelist mode always exempts the null Envelope Sender: the rendered whitelist map contains a `<>` entry (Postfix's `smtpd_null_access_lookup_key`), so `MAIL FROM:<>` — remote bounces and delivery status notifications addressed to your domain — is accepted even though it appears in no whitelist, as RFC 5321 requires. Blacklist-only configurations never reject unlisted Envelope Senders, so they need (and get) no exemption. Mail accepted through this exemption still passes through your Pipe-time Filters, where it can be Discarded if unwanted.

## The Catch-all Recipient Contract

The server accepts mail for **every recipient at the receiving domain**. Setup clears Postfix's `local_recipient_maps`, so no address under your domain is ever refused for being unknown: `anything@your-domain.com` is accepted, piped in, and stored. (The standard SMTP sanity checks still apply — recipients must be fully qualified, at a resolvable domain, and at *your* domain; the server never relays.)

This is a deliberate v1 contract, not a missing feature:

- **Recipient-level policy is application work.** Every Email row stores its Envelope Recipients (see [The Email Row](#3-the-email-row)); decide what an address means — route it, ignore it, delete the row — in your `EmailReceived`/`MalformedEmailReceived` listeners, or Discard unwanted mail with a Pipe-time Filter.
- **Static recipient maps were rejected.** A Postfix-side table of valid recipients would have to be synced from the application, and any staleness window means the server refuses legitimate mail with a permanent 550: an address your application just created would bounce its first replies until the next sync. Permanently destroying legitimate mail is the one failure this package is designed never to allow.
- **SMTP-time dynamic recipient validation is deliberately post-v1.** Refusing unknown recipients during the SMTP transaction — a Postfix policy service querying the application in real time — only makes sense designed against your application's actual address scheme, so v1 does not attempt it.

The cost of the contract: mail to nonexistent recipients (typos, dictionary spam) is accepted and stored rather than refused at SMTP time. If volume matters, clean up unwanted rows from your listeners — deleting an `Email` also deletes its raw file.

## Observing SMTP-time Rejections

Mail refused during the SMTP transaction (sender lists, SPF, HELO checks, rate limits, postscreen) never reaches your application — Postfix rejects it before pipe delivery. The mail-log importer closes that visibility gap: it tails the Postfix mail log from a persisted offset, parses reject events — including postscreen's enforce-mode drops (PREGREET, HANGUP, DNSBL rank), which never produce a `NOQUEUE` line — and dispatches a `Notifiable\ReceiveEmail\Events\SmtpRejectionObserved` event for each one. Log rotation is detected automatically (the importer restarts from the new file), and unparseable reject lines are skipped and counted, never fatal.

### 1. Give the app user read access to the mail log

On Ubuntu, `/var/log/mail.log` is owned by `syslog:adm`. Add your app user (e.g. `forge`) to the `adm` group:

```bash
sudo usermod -aG adm forge
```

Group membership takes effect on the next login; restart long-running processes (queue workers, the scheduler daemon) so they pick it up.

### 2. Schedule the importer

Register the command in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifiable:import-mail-log --isolated')
    ->everyMinute()
    ->withoutOverlapping();
```

The first run records the current end of the mail log and imports nothing, so enabling the importer on a long-lived server does not flood your listeners with historical rejections. To import the existing history instead, make the first run `php artisan notifiable:import-mail-log --from-beginning` (the flag has no effect once an offset is stored). You can also run the command manually at any time.

Overlapping imports read from the same offset and dispatch duplicate events, so the command implements Laravel's [`Isolatable`](https://laravel.com/docs/artisan#isolatable-commands) contract and the schedule above runs it with `--isolated`: a run that finds another import in progress is skipped with a success exit code. Pass `--isolated` on manual runs too whenever the scheduler is active — `withoutOverlapping()` only keeps scheduled runs from overlapping each other, while the `--isolated` mutex is shared across every entry point.

Each subsequent run resumes from the persisted offset. Delivery is **at-least-once with a minimal duplicate window**: the offset is written atomically after every dispatched rejection, so a crash, failed offset write, or throwing listener mid-run re-dispatches at most the single line that was in flight when the run died — never the whole batch. Make listeners idempotent (for example, key on `rawLine` plus its timestamp) if duplicates matter to your application.

### 3. Listen for rejections

```php
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;

class RecordSmtpRejection
{
    public function handle(SmtpRejectionObserved $event): void
    {
        $rejection = $event->rejection;

        $rejection->timestamp;      // CarbonImmutable
        $rejection->clientHost;     // string|null — null when the line only carries an IP
        $rejection->clientIp;       // string
        $rejection->envelopeSender; // string|null — '' is the null Envelope Sender, null when absent from the line
        $rejection->recipient;      // string|null
        $rejection->rejectionClass; // RejectionClass: EnvelopeList, Spf, Helo, RateLimit, Postscreen, Other
        $rejection->rawLine;        // string — the raw log line
    }
}
```

Postscreen drops happen before the client ever reaches an smtpd process, so PREGREET, HANGUP, and DNSBL-rank events carry only `clientIp` — `clientHost`, `envelopeSender`, and `recipient` are `null`.

### Importer configuration

| Key | Default | Description |
|-----|---------|-------------|
| `mail-log-path` | `/var/log/mail.log` | The Postfix mail log the importer reads. |
| `mail-log-offset-path` | `storage_path('app/receive_email/mail-log-offset.json')` | Where the importer persists its read position between runs. |

### Known limitations

- **Copytruncate-style rotation can re-dispatch already-seen lines.** The importer detects rotation by inode change and in-place truncation by the file shrinking below the stored offset. Rotation that copies and truncates the log in place (`logrotate`'s `copytruncate`) keeps the inode, so depending on timing the importer may re-dispatch lines it has already seen or resume mid-content. Ubuntu's default create-based rotation (new file, new inode) is detected reliably and is unaffected.
- **Lines logged between the importer's last run and a rotation may be missed.** When the log rotates, the importer restarts from the top of the new file; the unread tail of the previous file — up to one scheduler interval of lines — is never read. A tighter schedule shrinks the window.
- **A corrupt offset state file loses the stored position.** When the state file exists but cannot be read or parsed (a torn disk write, a manual edit), the importer warns loudly — in the command output and the application log — and restarts as a first run: it fast-forwards to the current end of the log and rewrites the state file, so rejections logged between the last successful import and that run are never dispatched. If the gap matters, run once with `--from-beginning` to re-import the entire log instead (listeners may see duplicates).
- **Parsed fields are best-effort extractions from attacker-influenced content.** `envelopeSender`, `recipient`, `clientHost`, and the rejection class are parsed out of log lines that embed client-supplied data (sender addresses, HELO strings, even pre-greeting bytes). Treat `rawLine` as the authoritative record and the parsed fields as conveniences — do not feed them into shell commands, queries, or HTML unescaped.

## Rejected and Failed Mail

The server is receive-only: it never sends, relays, or bounces mail. Mail refused at SMTP time is the sending server's problem (see above). Once Postfix has accepted a message and piped it into your app, the pipe command resolves it in one of the following ways:

| Outcome | Exit code | Disposition |
|---------|-----------|-------------|
| A Pipe-time Filter rejects the message | `0` | Discarded. The `EmailRejected` event is dispatched so your application retains visibility; no bounce is ever generated. A throwing `EmailRejected` listener is logged and never prevents the Discard. Filters run on parseable mail before anything is stored, so a rejected message leaves no row and no file. `$event->mail` is `null` when the rejected message could not be fully parsed. |
| The message is Malformed Mail (its headers cannot be parsed or stored) | `0` | Kept, never lost. The raw message and an envelope-only row are stored (`parsed_at` stays null) and `MalformedEmailReceived` is dispatched; `EmailReceived` fires only for parsed mail. |
| The pipe is misconfigured (e.g. `pipe-filter` or `pipe-command` is not a valid class), or the input exceeds `message-size-limit` | `75` (`EX_TEMPFAIL`) | Postfix keeps the message queued and retries later. |
| An unexpected failure occurs (database down, disk full, ...) | `75` (`EX_TEMPFAIL`) | Postfix keeps the message queued and retries later, so transient outages never destroy accepted mail. |

Exiting `EX_TEMPFAIL` for misconfiguration and oversize input is deliberate, even though those conditions look permanent: tempfail is the mail-preserving choice. Both are operator-fixable — correct the config (or reconcile `message-size-limit` with Postfix's `message_size_limit`, which normally stops oversize mail at SMTP time) and everything that queued up in the meantime delivers successfully. Any other exit would either destroy real mail or ask for a bounce this server can never send.

## Upgrading to v1

v1 changes behavior you may rely on; every subsection below is a breaking change. The decisions behind them are recorded in `docs/adr/`. In short: rejection moved to SMTP time and the server never bounces (ADR-0001), and ingestion became raw-first — the SMTP envelope is the row's identity, parsed headers are nullable enrichment, and Malformed Mail is kept instead of dropped (ADR-0003).

### Sender lists now match the Envelope Sender

The `sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, and `sender-address-blacklist` config lists previously matched the Header Sender (the `Sender:`/`From:` header) in PHP after the mail was accepted. They now match the Envelope Sender (the SMTP `MAIL FROM` address) before acceptance, and the built-in filter classes are no longer evaluated pipe-time (custom `EmailFilterContract` filters still run).

Where the two identities diverge — common for ESP-sent mail, e.g. header `From: alerts@stripe.com` with Envelope Sender `bounces@em5678.stripe.com` — the lists now apply to the envelope side, so whitelist the envelope domain (`em5678.stripe.com`), not the header domain (`stripe.com`). Review your lists against the actual Envelope Senders of mail you expect; the `SmtpRejectionObserved` event reports the Envelope Sender of anything being rejected.

Domain entries also gained subdomain matching: the previous PHP filters compared the domain exactly, while Postfix access maps under the default [`parent_domain_matches_subdomains`](https://www.postfix.org/postconf.5.html#parent_domain_matches_subdomains) setting match the domain itself **and every subdomain** — a `sender-domain-blacklist` entry `example.com` now also rejects `user@sub.example.com`, and a whitelisted domain now admits all of its subdomains. Audit your domain entries (whitelist entries especially) for subdomains you do not intend to cover; see [Envelope Sender filtering](#envelope-sender-filtering).

After changing the lists, run `sudo php artisan notifiable:sync-postfix` to compile them into the Postfix access maps (the setup command does this once; deploys should re-run it — see [Forge Deployment](#forge-deployment)).

### SPF verification is now the default

The `--with-spf` opt-in flag is gone: `notifiable:setup-postfix` now installs and configures SPF verification by default, because SPF is what makes the Envelope Sender trustworthy enough to filter on. Pass `--without-spf` to opt out. Inbound mail failing SPF is rejected at SMTP time; watch for `RejectionClass::Spf` via the importer if you need visibility.

### Rejected mail is Discarded, never bounced

The pipe command previously exited `EX_NOHOST` for filtered mail, asking Postfix to bounce — impossible on a server that cannot send, so it produced queue churn and double-bounces. Mail rejected by a Pipe-time Filter now exits `0`: the message is Discarded, `EmailRejected` is dispatched, and no bounce is requested. Transient failures (database down, disk full) exit `75` (`EX_TEMPFAIL`), so Postfix keeps the message queued and retries instead of destroying accepted mail.

If you monitored pipe failures via Postfix bounce activity, switch to listening for `EmailRejected` and `SmtpRejectionObserved` instead. The full exit-code table is in [Rejected and Failed Mail](#rejected-and-failed-mail); Malformed Mail also exits `0` but is *kept*, not Discarded — see [Malformed Mail is kept and announced](#malformed-mail-is-kept-and-announced-not-discarded) below.

### The emails schema is reshaped: identity from the envelope

The ULID is now the only row identity; everything header-derived is nullable enrichment (see [The Email Row](#3-the-email-row)):

- New columns: `envelope_sender` (nullable), `envelope_recipients` (JSON array), `client_address` (nullable), `queue_id` (nullable), and `parsed_at` (nullable timestamp).
- `message_id` and `sent_at` are now nullable, and `message_id` traded its `UNIQUE` constraint for a plain index. A forged or legitimately duplicate Message-ID can no longer send a second delivery into a multi-day tempfail loop; if you relied on the constraint for deduplication, that is now application policy.
- The `sender_ulid` foreign key is now nullable with `nullOnDelete`: deleting a `Sender` preserves its emails and their raw files. (The old `cascadeOnDelete` removed rows at the database layer, bypassing the Eloquent hook that deletes raw files — orphaned files on disk.)

The package ships create-only migrations, reshaped in place for v1 — there is no upgrade migration. A fresh install just migrates; an existing install must apply the deltas above with its own `ALTER TABLE` migration.

### The pipe command now receives the SMTP envelope

Two coupled changes:

- **Re-run setup.** The Postfix transport entry gained the envelope macros (`${sender} ${client_address} ${queue_id} ${recipient}`) and an empty `null_sender=` attribute, so the null Envelope Sender (`MAIL FROM:<>`, DSNs) stores as `null` — never as the literal `MAILER-DAEMON`. Until you re-run `sudo php artisan notifiable:setup-postfix`, the pipe receives no envelope data and the envelope columns stay empty (`null`, or `[]` for `envelope_recipients`).
- **`PipeCommandContract` changed.** `handle()` now receives the envelope alongside the parsed mail: `handle(ParsedMailContract $parsedMail, Envelope $envelope): void`. A custom pipe command must add the parameter; the default `StoreAndDispatch` records all four envelope fields on the row.

Rows are message-scoped: `destination_recipient_limit` is deliberately not set, so one delivery stores every Envelope Recipient on one row.

### Malformed Mail is kept and announced, not discarded

Accepted mail whose headers cannot be parsed was previously dropped with only a log line — accepted-then-vanished mail. It is now kept, never lost: the raw message and an envelope-only row are stored (`parsed_at` stays `null`) and the new `MalformedEmailReceived` event is dispatched. The raw file is also written — and checked: a `false` write throws `FailedToStoreException` — *before* the row commits, so a row can never exist without its file. Adjust expectations on both sides:

- `EmailReceived` keeps its parseable-mail contract and never fires for Malformed Mail. If your application must see *all* accepted mail, listen for both events — see [Listen for Incoming Emails](#2-listen-for-incoming-emails).
- Custom `EmailFilterContract` filters no longer see mail that turns out malformed: a filter touching an unparseable header lets the message fall through to be kept and announced. A Pipe-time Filter is not a barrier against deliberately malformed input.

### Platform requirements are now enforced

Setup verifies it is running as root on Ubuntu 24.04+ and aborts before changing anything otherwise (`--force` skips the OS check for other Debian-like systems). It also refuses to configure the pipe to run as `root`: the pipe user defaults to `$SUDO_USER`, so `sudo php artisan notifiable:setup-postfix` from your deploy user does the right thing without `--user`. The package's composer constraints are unchanged (PHP 8.2+, Laravel 12 or 13).

## Research References
- [How Postfix receives email](https://www.postfix.org/OVERVIEW.html#receiving)
- [Installing and configuring Postfix on Ubuntu](https://ubuntu.com/server/docs/install-and-configure-postfix)

## Credits
The solutions in this package are inspired by the following projects:
- [Mailcare](https://gitlab.com/mailcare/mailcare)
- [Laravel Mailbox](https://github.com/beyondcode/laravel-mailbox)
