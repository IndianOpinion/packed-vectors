<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

/**
 * Read-only stream wrapper over a real file that can return random short
 * reads and counts every byte it hands out.
 *
 * URL form: test-read://<real path>
 */
final class ReadStream
{
    public const SCHEME = 'test-read';

    /** When true, each read returns a random length from 1 to the request. */
    public static bool $shortReads = false;

    /** Highest file offset handed out so far, over all streams. */
    public static int $highWater = 0;

    /** Total bytes handed out, over all streams. */
    public static int $bytesOut = 0;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$shortReads = false;
        self::$highWater = 0;
        self::$bytesOut = 0;
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public static function url(string $path): string
    {
        return self::SCHEME . '://' . $path;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($mode !== 'rb' && $mode !== 'r') {
            return false;
        }
        $real = substr($path, strlen(self::SCHEME) + 3);
        if (!is_file($real)) {
            return false;
        }
        $h = fopen($real, 'rb');
        if ($h === false) {
            return false;
        }
        $this->handle = $h;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        assert($this->handle !== null);
        if ($count < 1) {
            return '';
        }
        if (self::$shortReads && $count > 1) {
            $count = mt_rand(1, $count);
        }
        $data = fread($this->handle, $count);
        if ($data === false) {
            return false;
        }
        self::$bytesOut += strlen($data);
        self::$highWater = max(self::$highWater, (int) ftell($this->handle));

        return $data;
    }

    public function stream_eof(): bool
    {
        assert($this->handle !== null);

        return feof($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        assert($this->handle !== null);

        return fstat($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = substr($path, strlen(self::SCHEME) + 3);
        if (!file_exists($real)) {
            return false;
        }

        return stat($real);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
