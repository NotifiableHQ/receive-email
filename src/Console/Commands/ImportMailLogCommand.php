<?php

namespace Notifiable\ReceiveEmail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Notifiable\ReceiveEmail\Events\SmtpRejectionObserved;
use Notifiable\ReceiveEmail\MailLog\MailLogOffsetStore;
use Notifiable\ReceiveEmail\MailLog\MailLogPosition;
use Notifiable\ReceiveEmail\MailLog\RejectionLineParser;
use RuntimeException;
use Throwable;

/**
 * Concurrent imports read the same offset and dispatch duplicate events;
 * Isolatable lets every entry point (scheduler and manual) contend on one
 * command mutex via --isolated.
 */
class ImportMailLogCommand extends Command implements Isolatable
{
    /** @var string */
    protected $signature = 'notifiable:import-mail-log
                            {--from-beginning : Import the existing log history on the first run instead of starting at the end}';

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

        $store = new MailLogOffsetStore(Config::string('receive_email.mail-log-offset-path'));

        try {
            $position = $store->get();
        } catch (RuntimeException $exception) {
            // The stored position is unrecoverable; the import must go on,
            // but silently restarting would hide that everything logged
            // since the last good offset is being skipped.
            $position = null;

            $message = $exception->getMessage().' '.($this->option('from-beginning')
                ? 'Continuing as a first run with --from-beginning: the entire log will be re-imported, and listeners may see rejections they have already processed.'
                : 'Continuing as a first run: rejections logged between the last successful import and now will NOT be dispatched. Run with --from-beginning to re-import the entire log instead (listeners may see duplicates).');

            $this->warn($message);
            Log::warning($message);
        }

        // On a first run the log holds arbitrarily old history; fast-forward
        // to the end without dispatching so listeners only see rejections
        // logged from now on, unless the user opts into the backlog.
        $fastForward = $position === null && ! $this->option('from-beginning');

        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            $this->error("Could not open mail log [{$logPath}].");

            return self::FAILURE;
        }

        // Stat the opened handle, not the path: the path can be rotated away
        // between a stat and the open, and the size at open is the anchor
        // the first-run fast-forward stops at — EOF moves while we read.
        $stat = fstat($handle);

        if ($stat === false) {
            fclose($handle);

            $this->error("Could not stat mail log [{$logPath}].");

            return self::FAILURE;
        }

        $inode = $stat['ino'];
        $size = $stat['size'];

        $offset = $this->resumeOffset($position, $inode, $size);

        $observed = 0;
        $unparseable = 0;

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            // The fast-forward consumes only lines that start before the
            // size captured at open: the anchor is checked before each read,
            // never after, so a line appended during the scan — even to a
            // file that was empty at open — is left at/beyond the persisted
            // offset for the next run, which dispatches it instead of
            // silently consuming it.
            while ($fastForward && $offset < $size && ($line = fgets($handle)) !== false) {
                // A line without a newline is still being written; leave it
                // (and the offset) for the next run.
                if (! str_ends_with($line, "\n")) {
                    break;
                }

                $offset += strlen($line);
            }

            while (! $fastForward && ($line = fgets($handle)) !== false) {
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

                // Persist after every dispatch so a failure further into the
                // batch never replays rejections that listeners already saw.
                $store->put(new MailLogPosition($inode, $offset));
            }

            $store->put(new MailLogPosition($inode, $offset));
        } catch (Throwable $exception) {
            $this->error("Import aborted after observing {$observed} rejection(s): {$exception->getMessage()} The next run resumes from the last persisted offset.");

            return self::FAILURE;
        } finally {
            fclose($handle);
        }

        if ($fastForward) {
            $this->info('No stored offset; starting at the current end of the mail log. Run with --from-beginning to import the existing history.');

            return self::SUCCESS;
        }

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
