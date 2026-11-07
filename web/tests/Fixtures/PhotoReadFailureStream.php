<?php

namespace Tests\Fixtures;

final class PhotoReadFailureStream
{
    public mixed $context;
    public static int $closes = 0;
    public static bool $stall = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if (self::$stall) return '';
        throw new \RuntimeException('Synthetic photo read failure');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$closes++;
    }
}
