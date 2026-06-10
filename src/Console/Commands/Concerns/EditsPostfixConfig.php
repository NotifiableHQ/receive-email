<?php

namespace Notifiable\ReceiveEmail\Console\Commands\Concerns;

use Illuminate\Support\Arr;
use Notifiable\ReceiveEmail\Support\PostfixDirectory;
use RuntimeException;

trait EditsPostfixConfig
{
    private function getConfigPath(string $config): string
    {
        $path = PostfixDirectory::path($config);

        if (! file_exists($path)) {
            throw new RuntimeException("The {$path} file does not exist!");
        }

        return $path;
    }

    private function editLine(string $filePath, string $regex, string $newLine): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        $matches = [];
        if (! preg_match($regex, $content, $matches)) {
            return null;
        }

        /** @var string $originalLine */
        $originalLine = Arr::first($matches);

        if (@file_put_contents($filePath, str_replace($originalLine, $newLine, $content)) === false) {
            throw new RuntimeException("Failed to write file: {$filePath}");
        }

        $this->line("--- Editing {$filePath} ---");
        $this->line("From: {$originalLine}");
        $this->line("To:  {$newLine}");

        return $originalLine;
    }

    private function upsertOrEditLine(string $filePath, string $regex, string $newLine): void
    {
        if ($this->editLine($filePath, $regex, $newLine) === null) {
            $this->upsertLine($filePath, $newLine);
        }
    }

    private function upsertLine(string $filePath, string $line): void
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        if (str($content)->contains($line)) {
            return;
        }

        if (@file_put_contents($filePath, "\n$line\n", FILE_APPEND) === false) {
            throw new RuntimeException("Failed to write file: {$filePath}");
        }

        $this->line("Append to {$filePath} : {$line}");
    }
}
