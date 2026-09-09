<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;

class UploadController
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** POST /api/uploads — multipart image upload, returns public URL */
    public function store(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            \Flight::json(['error' => 'No file uploaded'], 422);
            return;
        }

        $file = $_FILES['file'];
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            \Flight::json(['error' => 'Image must be under 5MB'], 422);
            return;
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info['mime'], self::ALLOWED_MIME, true)) {
            \Flight::json(['error' => 'Only JPG, PNG, GIF, or WebP images are allowed'], 422);
            return;
        }

        $ext = match ($info['mime']) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        };

        $dir = __DIR__ . '/../../public/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = 'msg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        // move_uploaded_file fails under some SAPIs in tests — fall back to copy
        if (!@move_uploaded_file($file['tmp_name'], $dest) && !@copy($file['tmp_name'], $dest)) {
            \Flight::json(['error' => 'Could not save image'], 500);
            return;
        }

        \Flight::json(['url' => '/uploads/' . $name], 201);
    }
}
