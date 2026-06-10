<?php

namespace Notifiable\ReceiveEmail\MailLog;

class MailLogOffsetStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * A missing or corrupt state file means starting over from the
     * beginning of the log, never failing.
     */
    public function get(): ?MailLogPosition
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $state = json_decode($contents, true);

        if (! is_array($state) || ! is_int($state['offset'] ?? null)) {
            return null;
        }

        $inode = $state['inode'] ?? null;

        return new MailLogPosition(is_int($inode) ? $inode : null, $state['offset']);
    }

    public function put(MailLogPosition $position): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->path, json_encode([
            'inode' => $position->inode,
            'offset' => $position->offset,
        ], JSON_THROW_ON_ERROR));
    }
}
