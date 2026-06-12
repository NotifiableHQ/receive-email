<?php

namespace Notifiable\ReceiveEmail\Tests\Fixtures;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * A Flysystem adapter with switchable failure modes, standing in for a
 * remote disk (S3) that misbehaves: failed writes raise UnableToWriteFile
 * and failed reads raise UnableToReadFile — exactly what the AWS adapter
 * raises — so Laravel's error translation ('throw' => false turns them
 * into false/null returns) is exercised for real. Successful operations
 * are backed by an in-memory array.
 */
class MisbehavingFilesystemAdapter implements FilesystemAdapter
{
    /** @var array<string, string> */
    private array $files = [];

    public function __construct(
        public bool $failWrites = false,
        public bool $failReads = false,
    ) {}

    public function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->failWrites) {
            throw UnableToWriteFile::atLocation($path, 'The adapter is misbehaving.');
        }

        $this->files[$path] = $contents;
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        if ($this->failReads || ! array_key_exists($path, $this->files)) {
            throw UnableToReadFile::fromLocation($path, 'The adapter is misbehaving.');
        }

        return $this->files[$path];
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open an in-memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function deleteDirectory(string $path): void {}

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->files[$path] ?? ''));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void {}

    public function copy(string $source, string $destination, Config $config): void {}
}
