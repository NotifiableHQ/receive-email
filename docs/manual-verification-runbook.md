# Manual Verification Runbook — the v1 gate

> **This runbook assumes the `road-to-v1` branch as of commit `c11409d`.**
> It executes the ten-scenario "Manual verification — gates the v1 tag" checklist from PR #11's body.
> Passing every scenario gates the **v1 tag**, not the merge (decision #10, scratchpad `road-to-v1-decisions`).

Every command, config key, log string, and exit code in this document was cross-checked against the
package source at that commit. If a command here disagrees with what you see on the box, suspect a
branch/commit mismatch first: `git log -1 --oneline` in the package checkout must show `c11409d`.

---

## Machines

| Name | What it is | Used by scenarios |
|------|------------|-------------------|
| **Box A** | Fresh Ubuntu 24.04, never had Postfix | 1, 3–10 |
| **Box B** | Ubuntu 24.04 with a pre-existing Postfix install | 2 |
| **Sender C** | Any machine with port-25 *egress* that is **not** Box A's MX and not in Box A's `mynetworks` | 10 (SPF), optional remote sends |

Run the scenarios on Box A in this order: **1 → 3 → 4 → 5 → 6 → 7 → 8 → 10 → 9**.
Scenario 9 (S3) changes the storage disk, so it goes last; scenario 10's importer steps depend on
scenario 1 having recorded the log offset. Scenario 2 runs independently on Box B.

## Pre-provisioning checklist (do this before booking time on the boxes)

- [ ] **Box A**: Ubuntu 24.04 VM with a public IP. Inbound **port 25 open** in every firewall layer
      (cloud security group *and* ufw). Many providers require a support ticket to unblock port 25.
- [ ] **Box B**: Ubuntu 24.04 VM (port 25 not strictly required — scenario 2 verifies configuration,
      not internet delivery, though a public IP makes the optional smoke test real).
- [ ] **Sender C**: a machine with **outbound port 25** (most cloud/residential IPs block it — verify
      with `nc -vz <boxA-ip> 25` before the session).
- [ ] **DNS** for the receiving domain (`$RECEIVE_DOMAIN` below), per the README:
  - `A` record for the host, `MX` record for `$RECEIVE_DOMAIN` pointing at it,
  - `TXT` SPF record: `v=spf1 mx -all` — scenario 10's SPF-fail test *depends on* this record existing,
  - allow time for propagation before the session.
- [ ] **TLS cert (optional but recommended)**: a Let's Encrypt cert + key for the host, to exercise
      the `--tls-cert`/`--tls-key` leg of setup. Without it, setup still completes (it warns that
      inbound SMTP is unencrypted).
- [ ] **The Laravel app** that will receive mail, deployable to both boxes, with the package installed
      **from `road-to-v1` at `c11409d`**:

  ```bash
  composer config repositories.receive-email vcs https://github.com/NotifiableHQ/receive-email
  composer require "notifiablehq/receive-email:dev-road-to-v1#c11409d"
  ```

- [ ] **MySQL** as the app database is recommended — the DB-down drill (scenario 7) is
      `systemctl stop mysql`. SQLite works with the documented chmod alternative.
- [ ] **Docker** on Box A for MinIO (scenario 9), or real S3 credentials + bucket instead.
- [ ] Budget ~2–3 hours for Box A, ~1 hour for Box B.

## Conventions

Set these in every shell you open on Box A/B:

```bash
RECEIVE_DOMAIN=mail.example.com          # the domain that receives mail (the MX-ed one)
APP_DIR=/home/deploy/app                 # the Laravel app root on the box
HELO=runbook-client.example.com          # any syntactically valid FQDN (HELO restrictions reject bare hostnames)
cd "$APP_DIR"
```

- `swaks` is the test mail client throughout. Install it on Box A and Sender C:

  ```bash
  sudo apt-get install -y swaks
  ```

- **Every send captures the queue ID** from swaks's `250 2.0.0 Ok: queued as <QID>` reply line.
  The pipe stores it in the row's `queue_id` column, which is how each scenario links the SMTP
  transaction to its database row. Set `QID=<value>` after each send.
- **DB queries**: examples are given as `sqlite3` one-liners and MySQL equivalents. Pick yours:

  ```bash
  # SQLite
  dbq() { sqlite3 "$APP_DIR/database/database.sqlite" "$1"; }
  # MySQL (adjust credentials)
  dbq() { mysql -N -u app -p'secret' appdb -e "$1"; }
  ```

- **Log locations**: Postfix logs to `/var/log/mail.log` (rsyslog); the app logs to
  `$APP_DIR/storage/logs/laravel.log`. Keep a `tail -f` on both in side terminals for the whole session:

  ```bash
  sudo tail -f /var/log/mail.log
  tail -f "$APP_DIR/storage/logs/laravel.log"
  ```

- **After editing `config/receive_email.php` or `.env`**: run `php artisan config:clear` (and re-run
  `php artisan config:cache` if your deploy caches config). The pipe boots a fresh artisan process per
  delivery, so cleared config takes effect on the next delivery.

### Verification listeners (install once, before scenario 3)

The events are the package's contract; make them observable by logging them. Add to
`app/Providers/AppServiceProvider.php::boot()`:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Events\EmailReceived;
use Notifiable\ReceiveEmail\Events\EmailRejected;
use Notifiable\ReceiveEmail\Events\MalformedEmailReceived;
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;

Event::listen(EmailReceived::class, fn ($e) => Log::info('runbook: EmailReceived', [
    'ulid' => $e->email->ulid, 'queue_id' => $e->email->queue_id,
]));
Event::listen(MalformedEmailReceived::class, fn ($e) => Log::info('runbook: MalformedEmailReceived', [
    'ulid' => $e->email->ulid, 'queue_id' => $e->email->queue_id,
]));
Event::listen(EmailRejected::class, fn ($e) => Log::info('runbook: EmailRejected', [
    'filter' => $e->filterClass,
]));
Event::listen(SmtpRejectionObserved::class, fn ($e) => Log::info('runbook: SmtpRejectionObserved', [
    'class' => $e->rejection->rejectionClass->name,
    'sender' => $e->rejection->envelopeSender,
    'raw' => $e->rejection->rawLine,
]));
```

---

## Scenario 1 — Fresh Ubuntu 24.04 box: setup, sync, importer

**Checklist box:** *Fresh Ubuntu 24.04 box: setup, sync, importer*

### Preconditions

- Box A freshly provisioned, DNS records live, port 25 open.
- No Postfix installed yet (`dpkg -l | grep postfix` is empty).

### Steps

**1. Deploy the app** (as your deploy user, e.g. `deploy`):

```bash
sudo apt-get update
sudo apt-get install -y php-cli php-mailparse composer git unzip   # add your php-* DB/driver extensions
# deploy the Laravel app to $APP_DIR (clone, composer install, .env with DB credentials, key:generate)
cd "$APP_DIR"
php artisan vendor:publish --provider="Notifiable\ReceiveEmail\ReceiveEmailServiceProvider" --tag=receive-email
php artisan migrate
php -m | grep mailparse        # must print: mailparse
```

**2. Run setup** (with the TLS flags if you provisioned a cert; drop them otherwise):

```bash
sudo php artisan notifiable:setup-postfix "$RECEIVE_DOMAIN" \
    --tls-cert=/etc/letsencrypt/live/$RECEIVE_DOMAIN/fullchain.pem \
    --tls-key=/etc/letsencrypt/live/$RECEIVE_DOMAIN/privkey.pem
```

Notes on what you should see, in order: the warning banner, Postfix package install (debconf
preseeded), a stream of `Set <parameter> = <value>` lines, SPF policy daemon install
(`postfix-policyd-spf-python` or `spf-engine`), `Syncing the Envelope Sender access maps`,
`Verifying the Postfix configuration`, and finally **`Postfix reloaded.`** with exit code 0.
The pipe user is auto-resolved from `$SUDO_USER` — it must be your deploy user, never root
(setup aborts on root).

**3. Verify the written configuration:**

```bash
sudo postfix check && echo CHECK-OK                  # silent + CHECK-OK
sudo postconf -x mydestination                       # must include $RECEIVE_DOMAIN
sudo postconf -M notifiable/unix                     # the pipe transport — see expected line below
sudo postconf -n | grep -E 'default_transport|relay_transport|local_recipient_maps|message_size_limit|postscreen_greet_action|maximal_queue_lifetime|bounce_queue_lifetime'
ls -l /etc/postfix/notifiable_sender_whitelist* /etc/postfix/notifiable_sender_blacklist*
systemctl is-active postfix                          # active
```

The `notifiable/unix` entry must be (whitespace-collapsed; `<user>` = your deploy user,
`maxproc` = the `pipe-concurrency` config, default 4):

```
notifiable unix - n n - 4 pipe flags=F user=<user> null_sender= argv=php <APP_DIR>/artisan notifiable:receive-email ${sender} ${client_address} ${queue_id} ${recipient}
```

The four macros and the **empty `null_sender=`** are load-bearing: they are scenarios 5 and 6.
(On an Envoyer/Forge zero-downtime layout, `<APP_DIR>` resolves to the `releases/.../current` path.)

**4. Verify sync idempotency** (setup already synced once):

```bash
sudo php artisan notifiable:sync-postfix
```

Expected output: **`The Postfix sender access configuration is already up to date.`** — no reload.

**5. Set up the importer:**

```bash
sudo usermod -aG adm "$(whoami)"     # read access to /var/log/mail.log
# log out and back in (group membership applies at login), then:
php artisan notifiable:import-mail-log
```

Expected first-run output: **`No stored offset; starting at the current end of the mail log. Run
with --from-beginning to import the existing history.`** and the offset file appears at
`storage/app/receive_email/mail-log-offset.json`. Run it once more:

```bash
php artisan notifiable:import-mail-log    # Observed 0 SMTP-time rejection(s).
```

Then register the schedule in `routes/console.php` per the README:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifiable:import-mail-log --isolated')
    ->everyMinute()
    ->withoutOverlapping();
```

> For the rest of this runbook, importer runs are shown as manual `php artisan
> notifiable:import-mail-log` invocations. If you enable the scheduler during the session, add
> `--isolated` to manual runs so they take turns with scheduled ones.

### Expected observable outcome

Setup exits 0 ending in `Postfix reloaded.`; `postfix check` is clean; `mydestination` covers the
domain; the pipe transport carries the envelope macros and `null_sender=`; re-running sync is a
no-op; the importer records its offset on first run and observes 0 on the second.

### Evidence line

```
- [x] Fresh Ubuntu 24.04 box: setup, sync, importer — Box A <ip>, setup exit 0 ("Postfix reloaded."),
  postfix check clean, mydestination=<output>, notifiable/unix=<pasted transport line>,
  re-sync "already up to date", importer first run recorded offset + second run "Observed 0".
```

---

## Scenario 2 — Existing Postfix box: setup is idempotent against prior config

**Checklist box:** *Box with an existing Postfix install: setup is idempotent against prior config*

> **Warning before you start:** setup converts the box to receive-only — `default_transport = error`
> kills all outbound mail, `local_recipient_maps` is cleared, and port 25 moves behind postscreen.
> Never run this against a production mail server whose existing behavior matters.

### Preconditions

Box B with the app deployed (scenario 1 step 1) and a *pre-existing* Postfix. To create a
deterministic "prior install":

```bash
echo "postfix postfix/main_mailer_type string Internet Site" | sudo debconf-set-selections
echo "postfix postfix/mailname string $RECEIVE_DOMAIN" | sudo debconf-set-selections
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postfix
# Markers: a foreign parameter setup must PRESERVE, and a prior value setup must REPLACE.
sudo postconf -e 'mynetworks = 127.0.0.0/8 [::1]/128 192.0.2.0/24'
sudo postconf -e 'smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination'
sudo systemctl restart postfix
```

### Steps

**1. Snapshot the prior configuration:**

```bash
sudo postconf -n  > /tmp/main-before.txt
sudo postconf -Mf > /tmp/master-before.txt
```

**2. Run setup** (same invocation as scenario 1 step 2). Expected: `Postfix is already installed.`
instead of an install, then the same flow to `Postfix reloaded.`

**3. Diff what changed:**

```bash
sudo postconf -n  > /tmp/main-after.txt
sudo postconf -Mf > /tmp/master-after.txt
diff /tmp/main-before.txt /tmp/main-after.txt
diff /tmp/master-before.txt /tmp/master-after.txt
```

**Pass criteria for the diff:**

- *Changed/added in main.cf*: **exactly** the parameters in Appendix A (including
  `smtpd_recipient_restrictions` replaced with the package's value + SPF policy hook, and
  `smtpd_sender_restrictions` now owned by sync) — and **nothing else**.
- *Preserved*: the foreign `mynetworks` marker line is byte-identical; `mydestination` untouched;
  any other prior parameter not in Appendix A untouched.
- *master.cf*: `smtp/inet` now runs `postscreen`; the `smtpd/pass`, `dnsblog/unix`, `tlsproxy/unix`,
  `notifiable/unix`, and (with SPF) `policy-spf/unix` services exist as in Appendix A.

**4. Prove idempotency — run setup again, unchanged:**

```bash
sudo php artisan notifiable:setup-postfix "$RECEIVE_DOMAIN" --tls-cert=... --tls-key=...
sudo postconf -n  > /tmp/main-after2.txt
sudo postconf -Mf > /tmp/master-after2.txt
diff /tmp/main-after.txt /tmp/main-after2.txt && echo MAIN-IDEMPOTENT
diff /tmp/master-after.txt /tmp/master-after2.txt && echo MASTER-IDEMPOTENT
```

Expected: both diffs **empty**. The second run's output contains **no `Set ...` lines** (every
parameter already matches — setup reads before writing) and the inner sync reports the maps
unchanged.

**5. The designed failure path (optional but recommended)** — a pre-existing `mydestination` that
doesn't cover the receiving domain is the classic foreign-config hazard; setup must catch it
*after* writing but *before* activating:

```bash
sudo postconf -e 'mydestination = unrelated.example.com, localhost'
sudo php artisan notifiable:setup-postfix "$RECEIVE_DOMAIN" --tls-cert=... --tls-key=...
```

Expected: exit code 1 with `mydestination does not include <domain> ... "relay access denied" ...`
plus the remediation hint, and the warning that written-but-inactive configuration will be activated
by the next sync run only after `postfix check` passes. Fix and re-run:

```bash
sudo postconf -e "mydestination = $RECEIVE_DOMAIN, localhost"
sudo php artisan notifiable:setup-postfix "$RECEIVE_DOMAIN" --tls-cert=... --tls-key=...   # succeeds
```

**6. Functional smoke test** — one valid send (scenario 3's command) must store a row.

### Evidence line

```
- [x] Existing-Postfix box: setup idempotent — Box B <ip>; before/after postconf -n diff touches only
  the owned parameter set (attached), foreign mynetworks preserved; second setup run: zero "Set" lines,
  empty config diff; mydestination failure path exercised (exit 1 + remediation, then clean re-run).
```

---

## Scenario 3 — Valid mail accepted, stored, parsed (`EmailReceived`)

**Checklist box:** *Valid mail accepted, stored, parsed (`EmailReceived`)*

### Preconditions

Scenario 1 complete on Box A; verification listeners installed; both log tails running.

### Steps

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" \
      --to "valid@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 3 valid"
```

Local sends are in `mynetworks`, so they bypass postscreen and the SPF policy check
(`permit_mynetworks` leads `smtpd_recipient_restrictions`) — that is deliberate here; the full
remote path is exercised in scenario 10 and the optional Sender C send. Capture the queue ID:

```bash
QID=<from "250 2.0.0 Ok: queued as ...">
```

### Expected observable outcome and verification

```bash
grep "$QID" /var/log/mail.log | tail -3
# ...status=sent (delivered via notifiable service)

grep 'runbook: EmailReceived' storage/logs/laravel.log | tail -1
# carries the row ulid and queue_id == $QID

dbq "SELECT ulid, envelope_sender, envelope_recipients, client_address, queue_id, message_id, parsed_at FROM emails WHERE queue_id = '$QID';"
```

Expected row state:

| column | expected |
|--------|----------|
| `envelope_sender` | `runbook@$RECEIVE_DOMAIN` |
| `envelope_recipients` | `["valid@$RECEIVE_DOMAIN"]` |
| `client_address` | `127.0.0.1` |
| `queue_id` | `$QID` |
| `message_id` | non-NULL (swaks generates one) |
| `parsed_at` | non-NULL |

Raw file + parsed read-back (interactive `php artisan tinker`, paste):

```php
$e = Notifiable\ReceiveEmail\Models\Email::query()->where('queue_id', '<QID>')->sole();
Storage::disk(config('receive_email.storage-disk'))->exists($e->path());   // true
$e->path();                                                                 // emails/<Ymd>/<ulid>
$e->parsedMail()->subject();                                                // "runbook scenario 3 valid"
$e->sender->address;                                                        // Header Sender relation populated
```

### Evidence line

```
- [x] Valid mail accepted, stored, parsed — queue_id <QID> → row <ulid>: envelope columns populated,
  parsed_at <ts>, message_id <id>; raw file exists at emails/<Ymd>/<ulid>; EmailReceived logged <ts>;
  mail.log status=sent (delivered via notifiable service).
```

---

## Scenario 4 — Malformed mail kept: row + raw file + `MalformedEmailReceived`

**Checklist box:** *Malformed mail kept: Email row + raw file + malformed-mail event, never lost*

Three payloads, each hitting a distinct parse-failure path in the enrichment stage
(`id()` → missing Message-ID; `date()` → missing Date; `date()` → present-but-unparseable Date,
the classification fixed in `380a061`). In `--data`, swaks replaces literal `\n` with CRLF.

### Steps

**4a — missing Message-ID:**

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "malformed-a@$RECEIVE_DOMAIN" \
      --data "Date: Thu, 12 Jun 2026 10:00:00 +0000\nFrom: runbook@$RECEIVE_DOMAIN\nTo: malformed-a@$RECEIVE_DOMAIN\nSubject: runbook 4a no message-id\n\nThis message has no Message-ID header."
```

**4b — missing Date:**

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "malformed-b@$RECEIVE_DOMAIN" \
      --data "Message-ID: <runbook-4b@example.com>\nFrom: runbook@$RECEIVE_DOMAIN\nTo: malformed-b@$RECEIVE_DOMAIN\nSubject: runbook 4b no date\n\nThis message has no Date header."
```

**4c — Date present but garbage:**

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "malformed-c@$RECEIVE_DOMAIN" \
      --data "Message-ID: <runbook-4c@example.com>\nDate: not a date\nFrom: runbook@$RECEIVE_DOMAIN\nTo: malformed-c@$RECEIVE_DOMAIN\nSubject: runbook 4c garbage date\n\nDate header present but unparseable."
```

Capture `QID` for each.

### Expected observable outcome and verification

For **each** of the three (substitute the queue ID):

- swaks gets `250 ... queued as <QID>` and mail.log shows `status=sent (delivered via notifiable
  service)` — exit `0`, **never** deferred, never bounced. 4c especially must NOT defer (a deferral
  here is the old multi-day tempfail-loop bug).
- laravel.log shows `runbook: MalformedEmailReceived` with the queue_id, and **no**
  `runbook: EmailReceived` for that queue_id.

```bash
dbq "SELECT ulid, envelope_sender, message_id, sender_ulid, sent_at, parsed_at FROM emails WHERE queue_id = '$QID';"
```

| column | expected |
|--------|----------|
| `envelope_sender` | `runbook@$RECEIVE_DOMAIN` (envelope survives) |
| `message_id`, `sender_ulid`, `sent_at` | **all NULL** — enrichment is all-or-nothing |
| `parsed_at` | **NULL** — this *is* the Malformed Mail marker |

(Note 4b/4c: the payload carries a Message-ID header, but `message_id` is still NULL — enrichment
reads every header before writing any, so one bad header nulls the whole layer. That is the contract.)

Raw file kept, byte-for-byte (tinker):

```php
$e = Notifiable\ReceiveEmail\Models\Email::query()->where('queue_id', '<QID>')->sole();
Storage::disk(config('receive_email.storage-disk'))->exists($e->path());   // true
$e->parsedMail()->subject();    // works — subject is readable
$e->parsedMail()->id();         // 4a: throws MalformedMailException ("[message-id] header is missing.")
$e->parsedMail()->date();       // 4b: missing-header throw; 4c: "[date] header cannot be parsed."
```

### Evidence line

```
- [x] Malformed mail kept — 3 payloads (no Message-ID <QID-a>, no Date <QID-b>, "Date: not a date"
  <QID-c>): each status=sent/exit 0, row kept with parsed_at NULL + all enrichment NULL, raw file
  present, MalformedEmailReceived logged; no EmailReceived, no deferral.
```

---

## Scenario 5 — Null Envelope Sender stores NULL, not MAILER-DAEMON

**Checklist box:** *Null Envelope Sender (`MAIL FROM:<>`) stores null, not `MAILER-DAEMON`*

This proves the `null_sender=` pipe attribute end-to-end: without it, Postfix substitutes the
literal `MAILER-DAEMON` into `${sender}`.

### Steps

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from '<>' \
      --to "dsn-test@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 5 null sender"
QID=<from the 250 reply>
```

(`--from '<>'` makes swaks send `MAIL FROM:<>` — watch for it in the swaks dialogue. The null
sender is exempt from `reject_non_fqdn_sender`/`reject_unknown_sender_domain` by design.)

### Expected observable outcome and verification

```bash
dbq "SELECT ulid, COALESCE(envelope_sender, '<<SQL NULL>>') AS envelope_sender, parsed_at FROM emails WHERE queue_id = '$QID';"
```

Expected: `envelope_sender` prints `<<SQL NULL>>` — a real SQL NULL. **Fail** if it prints
`MAILER-DAEMON` (missing `null_sender=`) or an empty string (normalization bypassed). The message
body is well-formed, so `parsed_at` is set and `runbook: EmailReceived` appears in laravel.log.

### Evidence line

```
- [x] Null Envelope Sender — MAIL FROM:<> (queue_id <QID>) → envelope_sender IS NULL (verified SQL
  NULL, not 'MAILER-DAEMON', not ''); EmailReceived logged.
```

---

## Scenario 6 — Multi-recipient delivery → one row, full Envelope Recipient array

**Checklist box:** *Multi-recipient delivery → one row with the full Envelope Recipient array*

Rows are message-scoped by decision: `destination_recipient_limit` is deliberately **not** set, so
one transaction with several `RCPT TO`s is one pipe invocation and one row.

### Steps

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" \
      --to "multi-a@$RECEIVE_DOMAIN,multi-b@$RECEIVE_DOMAIN,multi-c@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 6 multi-recipient"
QID=<from the 250 reply>
```

Watch the swaks dialogue: **three** `RCPT TO` commands, each answered `250`, in **one** transaction.

### Expected observable outcome and verification

```bash
sudo postconf -d default_destination_recipient_limit   # 50 — confirms no per-recipient splitting at 3
sudo postconf -n | grep destination_recipient_limit    # must print NOTHING (the parameter is unset)

dbq "SELECT COUNT(*) FROM emails WHERE queue_id = '$QID';"          # exactly 1
dbq "SELECT envelope_recipients FROM emails WHERE queue_id = '$QID';"
# ["multi-a@<domain>","multi-b@<domain>","multi-c@<domain>"]  — all three, one JSON array
```

mail.log shows the delivery under the single queue ID (`grep "$QID" /var/log/mail.log`); the row
count is the authoritative single-delivery proof. laravel.log shows exactly **one**
`runbook: EmailReceived` for the queue_id.

### Evidence line

```
- [x] Multi-recipient → one row — 3 RCPT TO in one transaction (queue_id <QID>): COUNT(*)=1,
  envelope_recipients=["multi-a@…","multi-b@…","multi-c@…"], one EmailReceived;
  destination_recipient_limit confirmed unset.
```

---

## Scenario 7 — DB down and storage down → EX_TEMPFAIL, queued, delivers after recovery

**Checklist box:** *DB down and storage down → `EX_TEMPFAIL`, message stays queued, delivers after recovery*

The pipe's transient-failure contract: exit 75 (`EX_TEMPFAIL`), Postfix keeps the message queued
(`maximal_queue_lifetime = 5d`) and redelivers. Nothing is lost.

### 7a — Database down

**Induce:**

```bash
sudo systemctl stop mysql
# SQLite alternative: chmod 000 "$APP_DIR/database/database.sqlite"*    (covers -wal/-shm)
```

**Send:**

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "dbdown@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 7a db down"
QID=<from the 250 reply>     # acceptance still succeeds — the failure is at pipe delivery
```

**Verify the tempfail:**

```bash
mailq                                       # $QID listed, with the deferral reason
grep "$QID" /var/log/mail.log | tail -3     # status=deferred (temporary failure...)
grep 'Exiting EX_TEMPFAIL' storage/logs/laravel.log | tail -1
# "Failed to receive email. Exiting EX_TEMPFAIL so Postfix keeps the message queued." (DB connection exception in context)
dbq "SELECT COUNT(*) FROM emails WHERE queue_id = '$QID';"   # 0 — run after recovery if using the DB-down variant; with MySQL stopped, run it after the restart below, BEFORE the flush
```

**Recover:**

```bash
sudo systemctl start mysql                  # SQLite: chmod 664 the files back
dbq "SELECT COUNT(*) FROM emails WHERE queue_id = '$QID';"   # still 0 — nothing delivered yet
sudo postqueue -f                           # flush: force immediate redelivery
sleep 5 && mailq                            # "Mail queue is empty"
grep "$QID" /var/log/mail.log | tail -2     # now status=sent (delivered via notifiable service)
dbq "SELECT ulid, parsed_at FROM emails WHERE queue_id = '$QID';"   # the row, parsed
```

### 7b — Storage down

**Induce** — make the storage disk root unwritable for the pipe user:

```bash
DISK_ROOT=$(php artisan tinker --execute="echo Storage::disk(config('receive_email.storage-disk'))->path('');")
echo "$DISK_ROOT"                          # sanity: the local disk root (e.g. .../storage/app/private)
chmod -R a-w "$DISK_ROOT"
```

**Send** (subject `runbook scenario 7b storage down`, recipient `storagedown@$RECEIVE_DOMAIN`),
capture `QID`, then verify exactly as in 7a: `mailq` shows it, mail.log `status=deferred`,
laravel.log shows the `Exiting EX_TEMPFAIL` line — with `FailedToStoreException` (the checked
`put()` returning false) or the underlying filesystem exception in its context.

**Recover:**

```bash
chmod -R u+w "$DISK_ROOT"
sudo postqueue -f
sleep 5 && mailq                            # empty
dbq "SELECT ulid, parsed_at FROM emails WHERE queue_id = '$QID';"   # row exists, parsed
# and the raw file exists (tinker: Storage::disk(...)->exists($e->path()) → true)
```

### Evidence line

```
- [x] DB down + storage down → EX_TEMPFAIL, queued, recovered — 7a queue_id <QID-a>: deferred in mailq,
  laravel.log EX_TEMPFAIL w/ DB exception, 0 rows while down; after systemctl start mysql + postqueue -f:
  queue empty, row delivered. 7b queue_id <QID-b>: same cycle via chmod -R a-w on <disk root>,
  FailedToStoreException in log, delivered after chmod restore + flush.
```

---

## Scenario 8 — Raw storage failure: never a committed row without its file

**Checklist box:** *Raw storage failure → tempfail; never a committed DB row without its file*

This sharpens scenario 7 into the invariant from ADR-0003: the file is written before the row
commits, and a failed row-commit deletes the just-written file. Both directions are observable
through counts.

### Steps

**Baseline counts** (storage healthy, DB healthy):

```bash
DISK_ROOT=$(php artisan tinker --execute="echo Storage::disk(config('receive_email.storage-disk'))->path('');")
ROWS0=$(dbq "SELECT COUNT(*) FROM emails;")
FILES0=$(find "$DISK_ROOT/emails" -type f | wc -l)
echo "rows=$ROWS0 files=$FILES0"            # the two counts must already be equal
```

**Direction 1 — storage write fails → no row, no file:**

```bash
chmod -R a-w "$DISK_ROOT"
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "invariant-1@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 8 storage-fail"
QID1=<from the 250 reply>
sleep 3
dbq "SELECT COUNT(*) FROM emails;"                   # == $ROWS0  (no row without a file)
find "$DISK_ROOT/emails" -type f | wc -l             # == $FILES0
mailq                                                # $QID1 deferred — the mail is NOT lost
chmod -R u+w "$DISK_ROOT"
```

**Direction 2 — DB commit fails after the raw write → file deleted, no orphan:**

```bash
sudo systemctl stop mysql        # (or the SQLite chmod variant)
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "invariant-2@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 8 db-fail-after-write"
QID2=<from the 250 reply>
sleep 3
find "$DISK_ROOT/emails" -type f | wc -l             # == $FILES0 — the file was written, then deleted on rollback
mailq                                                # $QID2 deferred
sudo systemctl start mysql
```

**Recover and prove convergence:**

```bash
sudo postqueue -f && sleep 5 && mailq                # empty
dbq "SELECT COUNT(*) FROM emails;"                   # == ROWS0 + 2
find "$DISK_ROOT/emails" -type f | wc -l             # == FILES0 + 2 — rows and files move in lockstep
dbq "SELECT queue_id, parsed_at FROM emails WHERE queue_id IN ('$QID1','$QID2');"
```

### Expected observable outcome

At every checkpoint, row count == file count. During the storage failure neither moves; during the
DB failure the file count stays flat (write-then-delete leaves no orphan); after recovery both
advance by exactly 2 and the two queue IDs are present and parsed.

### Evidence line

```
- [x] Raw-storage invariant — baseline rows=N files=N; storage-fail send <QID1>: rows N / files N
  (no row without file); db-fail send <QID2>: files N (rollback deleted the written file, no orphan);
  after recovery + flush: rows N+2 / files N+2, both queue IDs delivered.
```

---

## Scenario 9 — S3 disk end-to-end: store, `parsedMail()` read, delete

**Checklist box:** *S3 disk end-to-end: store, `parsedMail()` read, delete*

> Run this **last** on Box A — it switches the storage disk. MinIO stands in for S3 (same as CI);
> a real S3 bucket works identically (drop the endpoint/path-style keys and use real credentials).

### Preconditions

Docker on Box A; the app gets the S3 Flysystem adapter:

```bash
cd "$APP_DIR"
composer require "league/flysystem-aws-s3-v3:^3.0"
```

### Steps

**1. Start MinIO and create the bucket** (mirrors `.github/workflows/ci.yml`):

```bash
sudo docker run --detach --name minio --publish 9000:9000 \
    --env MINIO_ROOT_USER=minioadmin \
    --env MINIO_ROOT_PASSWORD=minioadmin \
    minio/minio:latest server /data

for attempt in $(seq 1 30); do
  curl --silent --fail http://127.0.0.1:9000/minio/health/live && break
  sleep 1
done

sudo apt-get install -y awscli
export AWS_ACCESS_KEY_ID=minioadmin AWS_SECRET_ACCESS_KEY=minioadmin AWS_DEFAULT_REGION=us-east-1
aws --endpoint-url http://127.0.0.1:9000 s3 mb s3://receive-email
```

> If something already listens on 9000, publish `9100:9000` instead and use
> `http://127.0.0.1:9100` as the endpoint everywhere below — then double-check your client is
> actually talking to the container (`curl http://127.0.0.1:9100/minio/health/live`).

**2. Point the app at the S3 disk.** In `.env` (these are the keys the stock
`config/filesystems.php` `s3` disk reads — bucket, key/secret, endpoint, path-style):

```dotenv
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=receive-email
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

In `config/receive_email.php`:

```php
'storage-disk' => 's3',
```

Then:

```bash
php artisan config:clear      # re-run config:cache instead if your deploy caches config
```

**3. Send → verify the object exists:**

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from "runbook@$RECEIVE_DOMAIN" --to "s3-test@$RECEIVE_DOMAIN" \
      --header "Subject: runbook scenario 9 s3"
QID=<from the 250 reply>

aws --endpoint-url http://127.0.0.1:9000 s3 ls "s3://receive-email/emails/$(date +%Y%m%d)/"
# one object named by the row's ULID
```

(The upload is synchronous inside the pipe — a delivery to a remote disk holds one of the
`pipe-concurrency` slots until it completes; that's the README's documented caveat, observable as
slightly slower `status=sent` lines.)

**4. `parsedMail()` read-back over S3** (`php artisan tinker`, paste):

```php
$e = Notifiable\ReceiveEmail\Models\Email::query()->where('queue_id', '<QID>')->sole();
$e->parsed_at !== null;                                       // true
Storage::disk('s3')->exists($e->path());                      // true
$mail = $e->parsedMail();                                     // streams (re-downloads) from MinIO
$mail->subject();                                             // "runbook scenario 9 s3"
$mail->text();                                                // the body, non-empty
```

**5. Delete → object gone:**

```php
$path = $e->path();
$e->delete();                                                 // model deleted-hook removes the raw file
Storage::disk('s3')->exists($path);                           // false
```

```bash
aws --endpoint-url http://127.0.0.1:9000 s3 ls "s3://receive-email/emails/$(date +%Y%m%d)/"
# the object is gone
dbq "SELECT COUNT(*) FROM emails WHERE queue_id = '$QID';"    # 0
```

**6. Restore** `'storage-disk' => 'local'` + `php artisan config:clear` if anything else still
needs to run, and `sudo docker rm -f minio` when done.

### Evidence line

```
- [x] S3 end-to-end — MinIO @ 127.0.0.1:9000, disk 's3' (path-style): send <QID> stored object
  s3://receive-email/emails/<Ymd>/<ulid>; parsedMail() re-read over S3 returned subject+body;
  $email->delete() removed row AND object (aws s3 ls empty, COUNT 0).
```

---

## Scenario 10 — Sender-list / SPF rejection observed via the importer

**Checklist box:** *Sender-list / SPF rejection observed through the mail-log importer*

SMTP-time rejections never reach the app; the importer closes the gap (ADR-0002). **Order matters:**
the importer's offset must be current *before* you induce the rejection — a first run fast-forwards
past existing log content.

### 10a — Envelope Sender blacklist rejection

**1. Configure and sync.** In `config/receive_email.php`:

```php
'sender-address-blacklist' => ['blocked-sender@example.com'],
```

```bash
php artisan config:clear
sudo php artisan notifiable:sync-postfix
# Wrote /etc/postfix/notifiable_sender_blacklist (1 entries)
# Set smtpd_sender_restrictions = check_sender_access hash:/etc/postfix/notifiable_sender_blacklist, reject_non_fqdn_sender, reject_unknown_sender_domain
# Postfix reloaded.

sudo postmap -q blocked-sender@example.com hash:/etc/postfix/notifiable_sender_blacklist   # REJECT
```

**2. Bring the importer's offset current:**

```bash
php artisan notifiable:import-mail-log     # Observed 0 SMTP-time rejection(s).
```

**3. Induce the rejection** (sender restrictions carry no `permit_mynetworks`, so localhost works):

```bash
swaks --server 127.0.0.1 --port 25 --helo "$HELO" \
      --from blocked-sender@example.com \
      --to "anything@$RECEIVE_DOMAIN"
```

Expected: swaks shows a **`554 5.7.1 ... Sender address rejected: Access denied`** reply and exits
non-zero — refused during the transaction, never accepted, never bounced.

```bash
grep 'NOQUEUE: reject:' /var/log/mail.log | tail -1
# postfix/smtpd[...]: NOQUEUE: reject: RCPT from ...: 554 5.7.1 <blocked-sender@example.com>: Sender address rejected: Access denied; from=<blocked-sender@example.com> to=<anything@...>
```

**4. Observe through the importer:**

```bash
php artisan notifiable:import-mail-log     # Observed 1 SMTP-time rejection(s).
grep 'runbook: SmtpRejectionObserved' storage/logs/laravel.log | tail -1
# class: EnvelopeList, sender: blocked-sender@example.com, raw: <the NOQUEUE line>
```

**5. Clean up:** empty the blacklist in config, `php artisan config:clear`, re-run
`sudo php artisan notifiable:sync-postfix`.

### 10b — SPF rejection (from Sender C)

**Preconditions:** the `v=spf1 mx -all` TXT record on `$RECEIVE_DOMAIN` is live
(`dig +short TXT $RECEIVE_DOMAIN` from Sender C); Sender C is not the MX and not in `mynetworks`;
port-25 egress verified. On Box A, bring the importer current again (step 2 above).

**From Sender C** — forge the receiving domain as the Envelope Sender; SPF must fail because
Sender C is not the domain's MX:

```bash
swaks --server <boxA-ip-or-hostname> --port 25 --helo client.example.com \
      --from "forged@$RECEIVE_DOMAIN" \
      --to "spf-test@$RECEIVE_DOMAIN"
```

Expected: a 5xx reply whose text contains **`Message rejected due to: SPF fail`** (policyd-spf), and
swaks exits non-zero. The first connection may pause a few seconds — that is postscreen.

**On Box A:**

```bash
grep 'SPF' /var/log/mail.log | tail -3                      # the NOQUEUE reject line with the SPF reason
php artisan notifiable:import-mail-log                      # Observed 1 SMTP-time rejection(s).
grep 'runbook: SmtpRejectionObserved' storage/logs/laravel.log | tail -1
# class: Spf, sender: forged@<domain>
```

### Evidence line

```
- [x] Sender-list / SPF rejection observed — blacklist: 554 "Sender address rejected: Access denied"
  at SMTP time, importer "Observed 1", SmtpRejectionObserved class=EnvelopeList (raw line attached);
  SPF: forged MAIL FROM @<domain> from Sender C <ip> rejected "Message rejected due to: SPF fail",
  importer "Observed 1", class=Spf.
```

---

## Appendix A — Configuration surface owned by setup/sync (the scenario-2 diff oracle)

`postconf -n` after setup must differ from "before" by exactly these `main.cf` parameters
(values as written by `c11409d`; `message_size_limit` and the pipe's `maxproc` come from
`config/receive_email.php`):

```
myhostname = <domain>
smtpd_recipient_restrictions = permit_mynetworks, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination[, check_policy_service unix:private/policy-spf]
local_recipient_maps =
default_transport = error
relay_transport = error
message_size_limit = 26214400
smtpd_helo_required = yes
smtpd_helo_restrictions = reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname
disable_vrfy_command = yes
smtpd_banner = $myhostname ESMTP
smtpd_client_connection_rate_limit = 30
smtpd_client_message_rate_limit = 60
smtpd_client_recipient_rate_limit = 120
smtpd_error_sleep_time = 1s
smtpd_soft_error_limit = 5
smtpd_hard_error_limit = 10
postscreen_greet_action = enforce
smtpd_data_restrictions = reject_unauth_pipelining
smtpd_timeout = 120s
maximal_queue_lifetime = 5d
bounce_queue_lifetime = 5d
smtpd_sender_restrictions = <owned by notifiable:sync-postfix; with empty lists: reject_non_fqdn_sender, reject_unknown_sender_domain>
# with --tls-cert/--tls-key:
smtpd_tls_cert_file = <cert>
smtpd_tls_key_file = <key>
smtpd_tls_security_level = may
smtpd_tls_protocols = >=TLSv1.2
smtpd_tls_loglevel = 1
smtp_tls_security_level = none
# without --without-spf:
policy-spf_time_limit = 3600s
```

`master.cf` services (compare via `postconf -Mf`):

```
smtp/inet        → smtp inet n - - - 1 postscreen
smtpd/pass       → smtpd pass - - - - - smtpd -o content_filter=notifiable:dummy
dnsblog/unix     → dnsblog unix - - - - 0 dnsblog
tlsproxy/unix    → tlsproxy unix - - - - 0 tlsproxy
notifiable/unix  → notifiable unix - n n - <pipe-concurrency> pipe flags=F user=<user> null_sender= argv=php <app>/artisan notifiable:receive-email ${sender} ${client_address} ${queue_id} ${recipient}
policy-spf/unix  → policy-spf unix - n n - 0 spawn user=policyd-spf argv=/usr/bin/policyd-spf   (with SPF)
```

Files setup/sync create under `/etc/postfix/`: `notifiable_sender_whitelist`(+`.db`),
`notifiable_sender_blacklist`(+`.db`), and a transient `notifiable_reload_pending` marker that
exists only between a config write and the next successful reload.

Everything **not** in this list (e.g. `mynetworks`, `inet_interfaces`, `mydestination`) must be
untouched by setup. `mydestination` is *verified*, never written — if it doesn't cover the domain,
setup fails with the remediation command.

## Appendix B — Quick triage reference

| Want to see | Command |
|-------------|---------|
| Queue contents / deferral reasons | `mailq` (or `postqueue -p`) |
| Force redelivery now | `sudo postqueue -f` |
| Full queued message | `sudo postcat -q <QID>` |
| Pipe delivery results | `grep -E 'status=(sent|deferred)' /var/log/mail.log \| tail` |
| SMTP-time rejections | `grep 'NOQUEUE: reject:' /var/log/mail.log \| tail` |
| Pipe tempfail reasons (app side) | `grep 'EX_TEMPFAIL' storage/logs/laravel.log \| tail` |
| Event evidence | `grep 'runbook:' storage/logs/laravel.log \| tail` |
| Resolve the local storage root | `php artisan tinker --execute="echo Storage::disk(config('receive_email.storage-disk'))->path('');"` |
| Row state for a send | `dbq "SELECT ulid, envelope_sender, envelope_recipients, client_address, queue_id, message_id, sent_at, parsed_at FROM emails WHERE queue_id='<QID>';"` |

Pipe exit classes (from `ReceiveEmailCommand` / the README table): `0` = stored & announced
(parsed → `EmailReceived`; Malformed Mail → kept + `MalformedEmailReceived`) **or** Discarded by a
Pipe-time Filter (`EmailRejected`, no row, no file); `75` (`EX_TEMPFAIL`) = transient failure,
misconfiguration, or oversize input — Postfix keeps the message queued and retries.

## Evidence table — paste into PR #11 when checking the boxes

| # | Checklist box | Proof captured | Evidence (queue IDs, ULIDs, outputs) |
|---|---------------|----------------|--------------------------------------|
| 1 | Fresh Ubuntu 24.04 box: setup, sync, importer | setup exit 0, `postfix check` clean, transport line, re-sync no-op, importer first run | |
| 2 | Existing-Postfix box: idempotent setup | before/after `postconf -n`/`-Mf` diffs, empty second-run diff, mydestination failure path | |
| 3 | Valid mail accepted, stored, parsed | row + file + `EmailReceived` for QID | |
| 4 | Malformed mail kept, never lost | 3 QIDs, rows w/ `parsed_at` NULL, files present, `MalformedEmailReceived` ×3 | |
| 5 | `MAIL FROM:<>` → `envelope_sender` NULL | SQL-NULL proof for QID, not MAILER-DAEMON | |
| 6 | Multi-recipient → one row, full array | COUNT=1 + 3-element JSON array for QID | |
| 7 | DB down / storage down → tempfail → recover | deferred QIDs, EX_TEMPFAIL log lines, post-recovery delivery | |
| 8 | No committed row without its file | row/file counts at each checkpoint (N → N → N → N+2/N+2) | |
| 9 | S3 end-to-end: store, read, delete | object listed, tinker read-back, object gone after delete | |
| 10 | Sender-list / SPF rejection via importer | 554/550 transcripts, `Observed 1` ×2, classes EnvelopeList + Spf | |

When all ten rows carry evidence: check the boxes in PR #11's body, attach this table in a PR
comment, and tag **v1**.
