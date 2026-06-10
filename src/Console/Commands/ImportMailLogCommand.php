<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;
use Notifiable\ReceiveEmail\MailLog\MailLogOffsetStore;
use Notifiable\ReceiveEmail\MailLog\MailLogPosition;
use Notifiable\ReceiveEmail\MailLog\RejectionLineParser;

class ImportMailLogCommand extends Command
{
    /** @var string */
    protected $signature = 'notifiable:import-mail-log';

    /** @var string */
    protected $description = 'Import SMTP-time rejections from the Postfix mail log and dispatch SmtpRejectionObserved events.';

    public function handle(RejectionLineParser $parser): int
    {
        $logPath = Config::string('receive_email.mail-log-path');

        clearstatcache(true, $logPath);

        if (! is_file($logPath) || ! is_readable($logPath)) {
            $this->error("Mail log [{$logPath}] is missing or not readable. Ensure the app user can read it (on Ubuntu, add the user to the adm group).");

            return self::FAILURE;
        }

        $inode = fileinode($logPath);
        $inode = $inode === false ? null : $inode;

        $size = filesize($logPath);
        $size = $size === false ? 0 : $size;

        $store = new MailLogOffsetStore(Config::string('receive_email.mail-log-offset-path'));

        $offset = $this->resumeOffset($store->get(), $inode, $size);

        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            $this->error("Could not open mail log [{$logPath}].");

            return self::FAILURE;
        }

        $observed = 0;
        $unparseable = 0;

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            while (($line = fgets($handle)) !== false) {
                // A line without a newline is still being written; leave it
                // (and the offset) for the next run.
                if (! str_ends_with($line, "\n")) {
                    break;
                }

                $offset += strlen($line);
                $line = rtrim($line, "\r\n");

                if (! $parser->isRejectionLine($line)) {
                    continue;
                }

                $rejection = $parser->parse($line);

                if ($rejection === null) {
                    $unparseable++;

                    continue;
                }

                event(new SmtpRejectionObserved($rejection));
                $observed++;
            }
        } finally {
            fclose($handle);
        }

        $store->put(new MailLogPosition($inode, $offset));

        $this->info("Observed {$observed} SMTP-time rejection(s).");

        if ($unparseable > 0) {
            $this->warn("Skipped {$unparseable} unparseable rejection line(s).");
        }

        return self::SUCCESS;
    }

    private function resumeOffset(?MailLogPosition $position, ?int $inode, int $size): int
    {
        if ($position === null) {
            return 0;
        }

        // A different inode means the log was rotated; an offset beyond the
        // end of the file means it was truncated. Restart from the top.
        if ($position->inode !== $inode || $position->offset > $size) {
            return 0;
        }

        return $position->offset;
    }
}
