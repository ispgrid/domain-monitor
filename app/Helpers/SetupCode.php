<?php

namespace App\Helpers;

/**
 * One-time code that proves the person running the web installer can see the server's files.
 * It's written to .setup-code in the app folder (or logs/ when the app folder is read-only,
 * as in the Docker image) and deleted once the installation is done.
 */
final class SetupCode
{
    private const FILE = '.setup-code';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 12;

    /**
     * The code, created on first use. Null when no folder is writable.
     */
    public static function ensure(): ?string
    {
        $existing = self::read();
        if ($existing !== null) {
            return self::format($existing);
        }

        $file = self::writablePath();
        if ($file === null) {
            return null;
        }

        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $oldUmask = umask(0077);
        $written = @file_put_contents($file, self::format($code) . "\n");
        umask($oldUmask);
        if ($written === false) {
            return null;
        }
        @chmod($file, 0600);

        return self::format($code);
    }

    /**
     * Whether the entered code matches (case, spaces and dashes don't matter)
     */
    public static function matches(string $input): bool
    {
        $expected = self::read();
        return $expected !== null && hash_equals($expected, self::normalize($input));
    }

    /**
     * Where the code file is, relative to the app folder, to tell the person installing
     */
    public static function displayPath(): string
    {
        $file = self::existingPath() ?? self::writablePath();
        return $file === null ? self::FILE : substr($file, strlen(self::root()) + 1);
    }

    /**
     * Delete the code once the installation is done
     */
    public static function remove(): void
    {
        foreach (self::candidates() as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function read(): ?string
    {
        $file = self::existingPath();
        if ($file === null) {
            return null;
        }
        $code = self::normalize((string)@file_get_contents($file));
        return strlen($code) === self::LENGTH ? $code : null;
    }

    private static function existingPath(): ?string
    {
        foreach (self::candidates() as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    private static function writablePath(): ?string
    {
        foreach (self::candidates() as $file) {
            if (is_dir(dirname($file)) && is_writable(dirname($file))) {
                return $file;
            }
        }
        return null;
    }

    private static function candidates(): array
    {
        $root = self::root();
        return [$root . '/' . self::FILE, $root . '/logs/' . self::FILE];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    private static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}
