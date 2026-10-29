<?php

namespace App\Helpers;

use App\Models\Setting;

/**
 * Custom application logo from Settings → Application.
 *
 * Stored under public/assets/uploads/branding with a random name, so browsers pick up
 * a new logo right away. SVG is not accepted: opened on its own it can run scripts.
 */
class BrandingHelper
{
    public const MAX_BYTES = 1024 * 1024;

    private const URL_DIR = '/assets/uploads/branding';

    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * URL of the uploaded logo, or null when the built-in logo is used
     */
    public static function logoUrl(): ?string
    {
        try {
            $file = (string)(new Setting())->getValue('app_logo', '');
        } catch (\Throwable $e) {
            return null;
        }

        if ($file === '' || !is_file(self::dir() . '/' . basename($file))) {
            return null;
        }
        return self::URL_DIR . '/' . rawurlencode(basename($file));
    }

    /**
     * Save an uploaded logo and use it. Returns an error message, or null when saved.
     */
    public static function saveUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The logo is too large. Maximum size is 1 MB.'
                : 'The logo could not be uploaded';
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'The logo is too large. Maximum size is 1 MB.';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = self::TYPES[$mime] ?? null;
        if ($ext === null || @getimagesize($file['tmp_name']) === false) {
            return 'The logo must be a PNG, JPG, WebP or GIF image';
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return 'Could not create ' . self::URL_DIR;
        }

        $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            return 'Could not save the logo';
        }

        self::deleteCurrent();
        (new Setting())->setValue('app_logo', $name);
        return null;
    }

    /**
     * Go back to the built-in logo
     */
    public static function remove(): void
    {
        self::deleteCurrent();
        (new Setting())->setValue('app_logo', '');
    }

    private static function deleteCurrent(): void
    {
        $current = basename((string)(new Setting())->getValue('app_logo', ''));
        if ($current !== '' && str_starts_with($current, 'logo-')) {
            @unlink(self::dir() . '/' . $current);
        }
    }

    private static function dir(): string
    {
        $webRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : dirname(__DIR__, 2) . '/public';
        return $webRoot . self::URL_DIR;
    }
}
