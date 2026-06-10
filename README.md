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

Whenever an email is received, the package will dispatch the `Notifiable\\ReceiveEmail\\Events\\EmailReceived` event. On Laravel 11 and above, you should use a listener class:

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

### 3. Accessing Email Data

The `Email` model gives you access to sender, recipients, subject, and body through the `parsedMail` method. Example:

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

When a pipe-time filter rejects a message it is discarded — never bounced — and `Notifiable\ReceiveEmail\Events\EmailRejected` is dispatched so your application retains visibility. See [Rejected and Failed Mail](#rejected-and-failed-mail).

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
sudo php artisan notifiable:sync-postfix
```

The command is idempotent and only reloads Postfix when something actually changed, so it is safe to run on every deploy. See [Envelope Sender filtering](#envelope-sender-filtering) for what it does.

8. To surface mail rejected during the SMTP transaction (sender lists, SPF, rate limits) inside your application, set up the mail-log importer — see [Observing SMTP-time Rejections](#observing-smtp-time-rejections).

## Configuration

After publishing the config file, you can tune the following settings in `config/receive_email.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `message-size-limit` | `26214400` (25MB) | Maximum inbound email size in bytes. Written to Postfix's `message_size_limit`, and enforced again by the pipe command as a guard. |
| `pipe-concurrency` | `4` | Maximum concurrent pipe processes. Maps to `maxproc` in `master.cf`. |
| `storage-disk` | `local` | Filesystem disk for storing raw email files. |
| `email-table` | `emails` | Table name for the Email model. |
| `sender-table` | `senders` | Table name for the Sender model. |
| `email-filters` | `[]` | Custom Pipe-time Filter classes, applied in order after a message is accepted and piped in. |

To apply changes to `message-size-limit` or `pipe-concurrency`, re-run the setup command.

### Envelope Sender filtering

The `sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, and `sender-address-blacklist` config lists are enforced at SMTP time as Postfix access maps keyed on the Envelope Sender — the SMTP `MAIL FROM` address, the identity SPF verifies. The setup command syncs them once; whenever the lists change, re-sync them (e.g. from a deploy hook, see [Forge Deployment](#forge-deployment)):

```bash
sudo php artisan notifiable:sync-postfix
```

When any whitelist has entries, the server rejects every sender not present in a whitelist. With only blacklists, listed senders are rejected and everyone else is accepted; a blacklisted sender is rejected even when a whitelist would also match it. Rejected mail is refused during the SMTP transaction with a 5xx response — the sending server is responsible for notifying its sender, and the rejection never reaches your application code (the [mail-log importer](#observing-smtp-time-rejections) closes that visibility gap).

Whitelist mode always exempts the null Envelope Sender: the rendered whitelist map contains a `<>` entry (Postfix's `smtpd_null_access_lookup_key`), so `MAIL FROM:<>` — remote bounces and delivery status notifications addressed to your domain — is accepted even though it appears in no whitelist, as RFC 5321 requires. Blacklist-only configurations never reject unlisted senders, so they need (and get) no exemption. Mail accepted through this exemption still passes through your Pipe-time Filters, where it can be discarded if unwanted.

## Observing SMTP-time Rejections

Mail refused during the SMTP transaction (sender lists, SPF, HELO checks, rate limits, postscreen) never reaches your application — Postfix rejects it before pipe delivery. The mail-log importer closes that visibility gap: it tails the Postfix mail log from a persisted offset, parses reject events, and dispatches a `Notifiable\ReceiveEmail\Events\SmtpRejectionObserved` event for each one. Log rotation is detected automatically (the importer restarts from the new file), and unparseable reject lines are skipped and counted, never fatal.

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

Schedule::command('notifiable:import-mail-log')
    ->everyMinute()
    ->withoutOverlapping();
```

Each run resumes from the previous offset, so a rejection is observed exactly once. You can also run it manually with `php artisan notifiable:import-mail-log`.

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
        $rejection->envelopeSender; // string|null — '' is the null sender, null when absent from the line
        $rejection->recipient;      // string|null
        $rejection->rejectionClass; // RejectionClass: EnvelopeList, Spf, Helo, RateLimit, Postscreen, Other
        $rejection->rawLine;        // string — the raw log line
    }
}
```

### Importer configuration

| Key | Default | Description |
|-----|---------|-------------|
| `mail-log-path` | `/var/log/mail.log` | The Postfix mail log the importer reads. |
| `mail-log-offset-path` | `storage_path('app/receive_email/mail-log-offset.json')` | Where the importer persists its read position between runs. |

## Rejected and Failed Mail

The server is receive-only: it never sends, relays, or bounces mail. Mail refused at SMTP time is the sending server's problem (see above). Once Postfix has accepted a message and piped it into your app, the pipe command resolves it in one of three ways:

| Outcome | Exit code | Disposition |
|---------|-----------|-------------|
| A pipe-time filter rejects the message | `0` | Discarded. The `EmailRejected` event is dispatched so your application retains visibility; no bounce is ever generated. |
| The message is malformed (e.g. missing required headers) | `0` | Discarded with a log entry. Retries cannot fix a broken message, and bouncing is impossible on a receive-only server. |
| An unexpected failure occurs (database down, disk full, ...) | `75` (`EX_TEMPFAIL`) | Postfix keeps the message queued and retries later, so transient outages never destroy accepted mail. |

## Upgrading to v1

The v1 hardening wave changes behavior you may rely on. The decisions behind these changes are recorded in `docs/adr/`.

### Sender lists now match the Envelope Sender (breaking)

The `sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, and `sender-address-blacklist` config lists previously matched the Header Sender (the `Sender:`/`From:` header) in PHP after the mail was accepted. They now match the Envelope Sender (the SMTP `MAIL FROM` address) before acceptance, and the built-in filter classes are no longer evaluated pipe-time (custom `EmailFilterContract` filters still run).

Where the two identities diverge — common for ESP-sent mail, e.g. header `From: alerts@stripe.com` with envelope sender `bounces@em5678.stripe.com` — the lists now apply to the envelope side, so whitelist the envelope domain (`em5678.stripe.com`), not the header domain (`stripe.com`). Review your lists against the actual envelope senders of mail you expect; the `SmtpRejectionObserved` event reports the envelope sender of anything being rejected.

After changing the lists, run `sudo php artisan notifiable:sync-postfix` to compile them into the Postfix access maps (the setup command does this once; deploys should re-run it — see [Forge Deployment](#forge-deployment)).

### SPF verification is now the default (breaking)

The `--with-spf` opt-in flag is gone: `notifiable:setup-postfix` now installs and configures SPF verification by default, because SPF is what makes the Envelope Sender trustworthy enough to filter on. Pass `--without-spf` to opt out. Inbound mail failing SPF is rejected at SMTP time; watch for `RejectionClass::Spf` via the importer if you need visibility.

### Pipe exit codes changed: rejected mail is discarded, not bounced

The pipe command previously exited `EX_NOHOST` for filtered mail, asking Postfix to bounce — impossible on a server that cannot send, so it produced queue churn and double-bounces. Now:

- Filtered and malformed mail exits `0`: the message is discarded, `EmailRejected` is dispatched for filtered mail, and no bounce is requested.
- Transient failures (database down, disk full) exit `75` (`EX_TEMPFAIL`): Postfix keeps the message queued and retries.

If you monitored pipe failures via Postfix bounce activity, switch to listening for `EmailRejected` and `SmtpRejectionObserved` instead.

### Platform requirements are now enforced

Setup verifies it is running as root on Ubuntu 24.04+ and aborts before changing anything otherwise (`--force` skips the OS check for other Debian-like systems). It also refuses to configure the pipe to run as `root`: the pipe user defaults to `$SUDO_USER`, so `sudo php artisan notifiable:setup-postfix` from your deploy user does the right thing without `--user`. The package's composer constraints are unchanged (PHP 8.2+, Laravel 12 or 13).

## Research References
- [How Postfix receives email](https://www.postfix.org/OVERVIEW.html#receiving)
- [Installing and configuring Postfix on Ubuntu](https://ubuntu.com/server/docs/install-and-configure-postfix)

## Credits
The solutions in this package are inspired by the following projects:
- [Mailcare](https://gitlab.com/mailcare/mailcare)
- [Laravel Mailbox](https://github.com/beyondcode/laravel-mailbox)
