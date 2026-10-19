<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CleanupReportWorkspaces extends Command
{
    protected $signature = 'reports:cleanup';

    protected $description = 'Remove abandoned private report workspaces older than one day';

    public function handle(): int
    {
        $root = storage_path('app/private/reporting');
        if (! is_dir($root)) return self::SUCCESS;
        if (is_link($root)) return self::FAILURE;
        $resolved = realpath($root);
        $failed = false;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || $entry->isLink() || ! $entry->isDir() || ! Str::isUuid($entry->getFilename())) continue;
            $target = $entry->getRealPath();
            if ($target === false || dirname($target) !== $resolved || $entry->getMTime() >= time() - 86400) continue;
            File::deleteDirectory($target);
            clearstatcache(true, $target);
            if (is_dir($target)) $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
