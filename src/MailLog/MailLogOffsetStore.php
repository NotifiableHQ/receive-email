<?php

namespace Notifiable\ReceiveEmail\MailLog;

use RuntimeException;

class MailLogOffsetStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * A missing or corrupt state file reads as no stored position, never
     * failing; the importer treats that as a first run.
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

    /**
     * Writes to a temporary file in the same directory and renames it into
     * place, so the state file always holds either the old or the new
     * position in full — never a torn write.
     *
     * @throws RuntimeException when the position cannot be persisted.
     */
    public function put(MailLogPosition $position): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create offset directory [{$directory}].");
        }

        $contents = json_encode([
            'inode' => $position->inode,
            'offset' => $position->offset,
        ], JSON_THROW_ON_ERROR);

        $temporary = $this->path.'.tmp';

        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            @unlink($temporary);

            throw new RuntimeException("Could not write offset file [{$temporary}].");
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not move offset file into place at [{$this->path}].");
        }
    }
}
