<?php

namespace Tests\Unit;

use App\Services\Media\PhotoImageProcessor;
use App\Services\Media\PhotoRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotoImageProcessorTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_normalized_outputs_preserve_ratio_remove_metadata_and_do_not_upscale(string $extension): void
    {
        $file = UploadedFile::fake()->image('synthetic.'.$extension, 2400, 1200);
        $processor = new PhotoImageProcessor;
        $result = $processor->process($file);
        try {
            $this->assertSame(1920, $result['metadata']['display_width']);
            $this->assertSame(960, $result['metadata']['display_height']);
            $this->assertSame(320, $result['metadata']['thumbnail_width']);
            $this->assertSame(160, $result['metadata']['thumbnail_height']);
            foreach ($result['streams'] as $name => $stream) {
                $bytes = stream_get_contents($stream);
                $this->assertSame($result['metadata'][$name.'_bytes'], strlen($bytes));
                $this->assertSame($result['metadata'][$name.'_sha256'], hash('sha256', $bytes));
                $this->assertSame($result['metadata'][$name.'_width'], getimagesizefromstring($bytes)[0]);
            }
        } finally {
            foreach ($result['streams'] as $stream) {
                fclose($stream);
            }
        }
        $small = $processor->process(UploadedFile::fake()->image('tiny.'.$extension, 24, 12));
        try {
            $this->assertSame(24, $small['metadata']['display_width']);
            $this->assertSame(24, $small['metadata']['thumbnail_width']);
        } finally {
            foreach ($small['streams'] as $stream) {
                fclose($stream);
            }
        }
    }

    public static function formats(): array
    {
        return [['jpg'], ['png']];
    }

    public function test_exact_input_byte_limit_is_accepted_and_oversize_is_rejected(): void
    {
        $bytes = UploadedFile::fake()->image('synthetic.png', 12, 8)->getContent();
        $processor = new PhotoImageProcessor;
        $file = UploadedFile::fake()->createWithContent('synthetic.png', str_pad($bytes, PhotoRules::MAX_BYTES, 'x'));
        $result = $processor->process($file);
        foreach ($result['streams'] as $stream) {
            fclose($stream);
        }
        $this->assertLessThan(PhotoRules::MAX_BYTES, $result['metadata']['display_bytes']);
        $this->expectException(ValidationException::class);
        $processor->process(UploadedFile::fake()->createWithContent('synthetic.png', str_pad($bytes, PhotoRules::MAX_BYTES + 1, 'x')));
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_content_dimensions_and_pixels_are_rejected(string $kind): void
    {
        $file = match ($kind) {
            'text' => UploadedFile::fake()->createWithContent('synthetic.jpg', 'not an image'),
            'corrupt' => UploadedFile::fake()->createWithContent('synthetic.png', "\x89PNG\r\n\x1a\ninvalid"),
            'extension' => UploadedFile::fake()->createWithContent('synthetic.jpg', UploadedFile::fake()->image('x.png')->getContent()),
            'gif' => UploadedFile::fake()->image('synthetic.gif'),
            'dimension' => UploadedFile::fake()->image('wide.png', 8193, 1),
            'pixels' => UploadedFile::fake()->image('large.png', 4000, 3001),
        };
        $this->expectException(ValidationException::class);
        (new PhotoImageProcessor)->process($file);
    }

    public static function invalidImages(): array
    {
        return array_map(fn ($kind) => [$kind], ['text', 'corrupt', 'extension', 'gif', 'dimension', 'pixels']);
    }

    public function test_exif_and_gps_payload_are_removed_and_orientation_is_normalized_when_supported(): void
    {
        $jpeg = UploadedFile::fake()->image('synthetic.jpg', 24, 12)->getContent();
        $tiff = "II\x2a\x00".pack('V', 8).pack('v', 1).pack('vvV', 0x112, 3, 1).pack('v', 6)."\0\0".pack('V', 0);
        $exif = "Exif\0\0".$tiff.'GPS_PRIVATE_SYNTHETIC';
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $result = (new PhotoImageProcessor)->process(UploadedFile::fake()->createWithContent('synthetic.jpg', $jpeg));
        try {
            foreach ($result['streams'] as $stream) {
                $encoded = stream_get_contents($stream);
                $this->assertStringNotContainsString('Exif', $encoded);
                $this->assertStringNotContainsString('GPS_PRIVATE_SYNTHETIC', $encoded);
            }
            $this->assertSame(function_exists('exif_read_data') ? 12 : 24, $result['metadata']['display_width']);
        } finally {
            foreach ($result['streams'] as $stream) {
                fclose($stream);
            }
        }
    }

    public function test_png_transparency_is_preserved_and_error_handler_is_restored(): void
    {
        $image = imagecreatetruecolor(8, 4);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 12, 34, 56, 96));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);
        $handler = static fn () => false;
        set_error_handler($handler);
        try {
            $result = (new PhotoImageProcessor)->process(UploadedFile::fake()->createWithContent('synthetic.png', $bytes));
            try {
                $decoded = imagecreatefromstring(stream_get_contents($result['streams']['display']));
                $this->assertSame(96, (imagecolorat($decoded, 0, 0) >> 24) & 127);
                unset($decoded);
            } finally {
                foreach ($result['streams'] as $stream) {
                    fclose($stream);
                }
            }
            $restored = set_error_handler(static fn () => false);
            restore_error_handler();
            $this->assertSame($handler, $restored);
        } finally {
            restore_error_handler();
        }
    }
}
