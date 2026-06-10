# Receive Email
Let your Laravel app receive emails.

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

5. SSH into your Forge server and go to your site directory. Then run the setup command as a `super user`. The command verifies it is running as root on Ubuntu 24.04+ before changing anything:
```bash
sudo php artisan notifiable:setup-postfix domain-that-receives-email.com \
    --tls-cert=/etc/nginx/ssl/your-application-domain.com/server.crt \
    --tls-key=/etc/nginx/ssl/your-application-domain.com/server.key
```

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

## Configuration

After publishing the config file, you can tune the following settings in `config/receive_email.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `message-size-limit` | `26214400` (25MB) | Maximum inbound email size in bytes. Written to Postfix's `message_size_limit`. |
| `pipe-concurrency` | `4` | Maximum concurrent pipe processes. Maps to `maxproc` in `master.cf`. |
| `storage-disk` | `local` | Filesystem disk for storing raw email files. |
| `email-table` | `emails` | Table name for the Email model. |
| `sender-table` | `senders` | Table name for the Sender model. |

To apply changes to `message-size-limit` or `pipe-concurrency`, re-run the setup command.

### Envelope Sender filtering

The `sender-domain-whitelist`, `sender-domain-blacklist`, `sender-address-whitelist`, and `sender-address-blacklist` config lists are enforced at SMTP time as Postfix access maps keyed on the Envelope Sender — the SMTP `MAIL FROM` address, the identity SPF verifies. The setup command syncs them once; whenever the lists change, re-sync them (e.g. from a deploy hook):

```bash
sudo php artisan notifiable:sync-postfix
```

When any whitelist has entries, the server rejects every sender not present in a whitelist. With only blacklists, listed senders are rejected and everyone else is accepted. Rejected mail is refused during the SMTP transaction with a 5xx response — the sending server is responsible for notifying its sender, and the rejection never reaches your application code.

> **Upgrade note:** these lists previously matched the Header Sender (the `Sender:`/`From:` header) in PHP after the mail was accepted. They now match the Envelope Sender before acceptance, and the built-in filter classes are no longer evaluated pipe-time (custom `EmailFilterContract` filters still run). Where the two identities diverge — common for ESP-sent mail, e.g. header `From: alerts@stripe.com` with envelope sender `bounces@em5678.stripe.com` — the lists now apply to the envelope side, so whitelist the envelope domain (`em5678.stripe.com`), not the header domain.

## Rejected and Failed Mail

The server is receive-only: it never sends, relays, or bounces mail. Once Postfix has accepted a message and piped it into your app, the pipe command resolves it in one of three ways:

| Outcome | Exit code | Disposition |
|---------|-----------|-------------|
| A pipe-time filter rejects the message | `0` | Discarded. The `EmailRejected` event is dispatched so your application retains visibility; no bounce is ever generated. |
| The message is malformed (e.g. missing required headers) | `0` | Discarded with a log entry. Retries cannot fix a broken message, and bouncing is impossible on a receive-only server. |
| An unexpected failure occurs (database down, disk full, ...) | `75` (`EX_TEMPFAIL`) | Postfix keeps the message queued and retries later, so transient outages never destroy accepted mail. |

## Research References
- [How Postfix receives email](https://www.postfix.org/OVERVIEW.html#receiving)
- [Installing and configuring Postfix on Ubuntu](https://ubuntu.com/server/docs/install-and-configure-postfix)

## Credits
The solutions in this package are inspired by the following projects:
- [Mailcare](https://gitlab.com/mailcare/mailcare)
- [Laravel Mailbox](https://github.com/beyondcode/laravel-mailbox)