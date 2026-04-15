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

5. SSH into your Forge server and go to your site directory. Then run the setup command as a `super user`:
```bash
sudo php artisan notifiable:setup-postfix domain-that-receives-email.com \
    --user=forge \
    --tls-cert=/etc/nginx/ssl/your-application-domain.com/server.crt \
    --tls-key=/etc/nginx/ssl/your-application-domain.com/server.key \
    --with-spf
```

> **Important:** Always pass `--user=forge` (or your deploy user) when running with `sudo`. Without it, the pipe transport will run as `root`.

**Available options:**

| Option | Description |
|--------|-------------|
| `--user=forge` | The system user Postfix runs the pipe command as. Required when using `sudo`. |
| `--tls-cert=` | Path to the TLS certificate file (PEM format). Enables opportunistic TLS for inbound SMTP. |
| `--tls-key=` | Path to the TLS private key file (PEM format). Must be provided together with `--tls-cert`. |
| `--with-spf` | Installs `postfix-policyd-spf-python` and configures SPF verification for inbound mail. |

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

## Research References
- [How Postfix receives email](https://www.postfix.org/OVERVIEW.html#receiving)
- [Installing and configuring Postfix on Ubuntu](https://ubuntu.com/server/docs/install-and-configure-postfix)

## Credits
The solutions in this package are inspired by the following projects:
- [Mailcare](https://gitlab.com/mailcare/mailcare)
- [Laravel Mailbox](https://github.com/beyondcode/laravel-mailbox)