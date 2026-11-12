<?php

declare(strict_types=1);

namespace IndianOpinion\PackedVectors\Tests\Support;

/**
 * Stream wrapper that writes through to a real file but fails once a byte
 * limit is reached: the write that crosses the limit is short, the next
 * one returns 0, as a full disk would.
 *
 * URL form: faulty-write://<real path>
 */
final class FaultyWriteStream
{
    public const SCHEME = 'faulty-write';

    public static int $limit = PHP_INT_MAX;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    private int $written = 0;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = substr($path, strlen(self::SCHEME) + 3);
        $h = fopen($real, $mode);
        if ($h === false) {
            return false;
        }
        $this->handle = $h;

        return true;
    }

    public function stream_write(string $data): int
    {
        assert($this->handle !== null);
        $room = self::$limit - $this->written;
        if ($room <= 0) {
            return 0;
        }
        $chunk = strlen($data) > $room ? substr($data, 0, $room) : $data;
        $n = (int) fwrite($this->handle, $chunk);
        $this->written += $n;

        return $n;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        assert($this->handle !== null);

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        assert($this->handle !== null);

        return (int) ftell($this->handle);
    }

    public function stream_flush(): bool
    {
        assert($this->handle !== null);

        return fflush($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function stream_eof(): bool
    {
        assert($this->handle !== null);

        return feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        assert($this->handle !== null);

        return fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
