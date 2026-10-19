<?php

namespace Tests\Fixtures {
    final class PhotoEncodingFailure
    {
        public static int $calls = 0;
        public static ?\WeakReference $image = null;
    }
}

namespace App\Services {
    // Loaded only by the isolated encoder-failure test process.
    function imagepng(\GdImage $image, mixed $output = null, int $quality = -1, int $filters = -1): bool
    {
        \Tests\Fixtures\PhotoEncodingFailure::$calls++;
        \Tests\Fixtures\PhotoEncodingFailure::$image = \WeakReference::create($image);
        echo 'synthetic partial output';

        return false;
    }
}
