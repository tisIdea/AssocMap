<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

final class PrivatePhotoResponse
{
    public const HEADERS = ['Cache-Control' => 'private, no-store, max-age=0',
        'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];

    /** The lifecycle has already staged, verified and authorized these bytes. */
    public static function stream(array $image, string $filename): Response
    {
        return response()->stream(static function () use ($image): void {
            try {
                fpassthru($image['stream']);
            } finally {
                fclose($image['stream']);
            }
        }, 200, self::HEADERS + [
            'Content-Type' => $image['mime_type'], 'Content-Length' => (string) $image['bytes'],
            'Content-Disposition' => 'inline; filename="'.$filename.'.'.$image['extension'].'"',
        ]);
    }
}
