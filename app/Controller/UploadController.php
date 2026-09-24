<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;

class UploadController
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB per image
    private const MAX_FILES = 8;                // per request
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * POST /api/uploads — multipart image upload.
     *
     * Accepts either `file` (single) or `files[]` (batch, up to MAX_FILES).
     * Always returns `urls` (array) plus `url` (first) for backwards compatibility,
     * so a caller can upload a whole gallery for an ad in one round trip.
     */
    public function store(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $files = $this->collectFiles();
        if ($files === []) {
            // PHP silently drops the whole body when it exceeds post_max_size,
            // leaving $_FILES empty — say so instead of "no file uploaded".
            $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $limit    = $this->bytesFromIni(ini_get('post_max_size') ?: '0');
            if ($declared > 0 && $limit > 0 && $declared > $limit) {
                \Flight::json([
                    'error' => 'Upload is larger than this server accepts. Try fewer or smaller photos.',
                ], 413);
                return;
            }
            \Flight::json(['error' => 'No file uploaded'], 422);
            return;
        }
        if (count($files) > self::MAX_FILES) {
            \Flight::json(['error' => 'Up to ' . self::MAX_FILES . ' images per upload'], 422);
            return;
        }

        // `context` only affects the filename prefix (ads vs message photos)
        $prefix = ($_POST['context'] ?? '') === 'ad' ? 'ad' : 'img';

        $dir = __DIR__ . '/../../public/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $urls = [];
        foreach ($files as $file) {
            $error = $this->validate($file);
            if ($error !== null) {
                // Nothing has been written for this file; report and stop so the
                // caller is not told a partial gallery succeeded.
                \Flight::json(['error' => $error], 422);
                return;
            }

            $ext  = $this->extensionFor($file['tmp_name']);
            $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . '/' . $name;

            // move_uploaded_file fails under some SAPIs — fall back to copy
            if (!@move_uploaded_file($file['tmp_name'], $dest) && !@copy($file['tmp_name'], $dest)) {
                \Flight::json(['error' => 'Could not save image'], 500);
                return;
            }

            $urls[] = '/uploads/' . $name;
        }

        \Flight::json(['urls' => $urls, 'url' => $urls[0]], 201);
    }

    /**
     * Normalise single `file` and batch `files[]` inputs into one list of
     * PHP upload entries, skipping empty slots.
     *
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private function collectFiles(): array
    {
        $out = [];

        if (!empty($_FILES['file']) && is_array($_FILES['file']['name'] ?? null) === false) {
            $out[] = $_FILES['file'];
        }

        if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[] = [
                    'name'     => (string) $_FILES['files']['name'][$i],
                    'type'     => (string) ($_FILES['files']['type'][$i] ?? ''),
                    'tmp_name' => (string) $_FILES['files']['tmp_name'][$i],
                    'error'    => (int) $_FILES['files']['error'][$i],
                    'size'     => (int) $_FILES['files']['size'][$i],
                ];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $file */
    private function validate(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Each image must be under 5MB',
                UPLOAD_ERR_PARTIAL                       => 'An upload was interrupted. Please try again.',
                default                                  => 'Upload failed. Please try again.',
            };
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'Each image must be under 5MB';
        }

        // Trust the file contents, not the client-supplied MIME type
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false || !in_array($info['mime'], self::ALLOWED_MIME, true)) {
            return 'Only JPG, PNG, GIF, or WebP images are allowed';
        }

        return null;
    }

    /** Convert a php.ini shorthand size ("8M", "512K") to bytes. */
    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit  = strtolower($value[strlen($value) - 1]);
        $num   = (int) $value;

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    private function extensionFor(string $tmpName): string
    {
        $info = @getimagesize($tmpName);

        return match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
