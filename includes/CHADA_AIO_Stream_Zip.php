<?php
/** Resumable append-only ZIP writer for one-pass source streaming. */
namespace CHADA_AIO;
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions become REST JSON errors or are escaped by the administrator UI.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate,WordPress.WP.AlternativeFunctions.file_system_operations_fseek,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private archive streams require binary seek/write semantics and remain confined by CHADA_AIO_Storage.

final class CHADA_AIO_Stream_Zip {
    private $handle;
    private $bytes_written = 0;

    public function __construct(string $archive, int $offset = 0) {
        CHADA_AIO_Storage::file_target($archive);
        $this->handle = fopen($archive, 'c+b');
        if (!$this->handle || ftruncate($this->handle, $offset) === false || fseek($this->handle, $offset) !== 0) {
            if ($this->handle) { fclose($this->handle); }
            throw new \RuntimeException(__('Cannot resume the streaming archive.', 'chada-aio'));
        }
    }

    public function add_file(string $source, string $name, bool $store = false): array {
        if (!is_file($source) || is_link($source) || !is_readable($source)) {
            throw new \RuntimeException(__('A package source file is missing, unreadable, or a symlink.', 'chada-aio'));
        }
        $stat = stat($source);
        if (!$stat || (int) $stat['size'] > CHADA_AIO_Config::MAX_ENTRY) {
            throw new \RuntimeException(__('A package entry exceeds the processing safety ceiling.', 'chada-aio'));
        }
        $input = fopen($source, 'rb');
        if (!$input) { throw new \RuntimeException(__('A package source file could not be opened.', 'chada-aio')); }
        try { return $this->add_stream($input, $name, $store, (int) $stat['size']); }
        finally { fclose($input); }
    }

    public function add_data(string $data, string $name, bool $store = false): array {
        $input = fopen('php://temp', 'w+b');
        if (!$input || fwrite($input, $data) !== strlen($data) || fseek($input, 0) !== 0) {
            if ($input) { fclose($input); }
            throw new \RuntimeException(__('A package metadata entry could not be prepared.', 'chada-aio'));
        }
        try { return $this->add_stream($input, $name, $store, strlen($data)); }
        finally { fclose($input); }
    }

    public function finish(array $entries): int {
        $central_offset = ftell($this->handle);
        if ($central_offset === false) { throw new \RuntimeException(__('The archive position could not be measured.', 'chada-aio')); }
        $central_size = 0; $zip64 = false;
        foreach ($entries as $entry) {
            $name = (string) $entry['name']; $name_length = strlen($name);
            if ($name_length > 65535) { throw new \RuntimeException(__('An archive path is too long.', 'chada-aio')); }
            $extra = ''; $compressed = (int) $entry['compressed_size']; $size = (int) $entry['size']; $offset = (int) $entry['offset'];
            if ($size > 0xffffffff) { $extra .= pack('vvV2', 0x0001, 8, $size & 0xffffffff, intdiv($size, 4294967296)); $size_field = 0xffffffff; $zip64 = true; }
            else { $size_field = $size; }
            if ($compressed > 0xffffffff) {
                $extra = pack('vvV2', 0x0001, 8, $size & 0xffffffff, intdiv($size, 4294967296)) . pack('V2', $compressed & 0xffffffff, intdiv($compressed, 4294967296));
                $compressed_field = 0xffffffff; $zip64 = true;
            } else { $compressed_field = $compressed; }
            if ($offset > 0xffffffff) {
                $extra .= pack('V2', $offset & 0xffffffff, intdiv($offset, 4294967296)); $offset_field = 0xffffffff; $zip64 = true;
            } else { $offset_field = $offset; }
            $version = $extra === '' ? 20 : 45;
            $record = pack('Vv6V3v5V2', 0x02014b50, 0x031e, $version, 0x0808, (int) $entry['method'], (int) $entry['time'], (int) $entry['date'],
                (int) $entry['crc'], $compressed_field, $size_field, $name_length, strlen($extra), 0, 0, 0, 0, $offset_field);
            $this->write($record . $name . $extra); $central_size += strlen($record) + $name_length + strlen($extra);
        }
        $count = count($entries); $end_offset = ftell($this->handle);
        if ($end_offset === false) { throw new \RuntimeException(__('The archive size could not be measured.', 'chada-aio')); }
        $zip64 = $zip64 || $count > 65535 || $central_offset > 0xffffffff || $central_size > 0xffffffff;
        if ($zip64) {
            $zip64_offset = $end_offset;
            $this->write(pack('Vv2V2v2V2', 0x06064b50, 44, 0, 45, 45, 0, 0, $count, 0, $count, 0, $central_size & 0xffffffff, intdiv($central_size, 4294967296), $central_offset & 0xffffffff, intdiv($central_offset, 4294967296)));
            $this->write(pack('V2V2', 0x07064b50, 0, $zip64_offset & 0xffffffff, intdiv($zip64_offset, 4294967296)));
            $this->write(pack('Vv2V2v', 0x06054b50, 0xffff, 0xffff, 0xffff, 0xffff, 0xffffffff, 0xffffffff, 0));
        } else {
            $this->write(pack('Vv4V2v', 0x06054b50, 0, 0, $count, $count, $central_size, $central_offset, 0));
        }
        fflush($this->handle); $size = ftell($this->handle); fclose($this->handle); $this->handle = null;
        if ($size === false) { throw new \RuntimeException(__('The completed archive size could not be measured.', 'chada-aio')); }
        return $size;
    }

    public function close(): void { if (is_resource($this->handle)) { fclose($this->handle); $this->handle = null; } }

    /** @param resource $input */
    private function add_stream($input, string $name, bool $store, int $expected_size): array {
        CHADA_AIO_Storage::relative($name); $name_length = strlen($name);
        if ($name_length > 65535) { throw new \RuntimeException(__('An archive path is too long.', 'chada-aio')); }
        $method = $store ? 0 : 8; $flags = 0x0808; $offset = ftell($this->handle);
        if ($offset === false) { throw new \RuntimeException(__('The archive position could not be measured.', 'chada-aio')); }
        [$time, $date] = $this->dos_time();
        $this->write(pack('Vv5V3v2', 0x04034b50, 20, $flags, $method, $time, $date, 0, 0, 0, $name_length, 0) . $name);
        $hash = hash_init('sha256'); $crc = hash_init('crc32b'); $size = 0; $compressed = 0; $deflater = null;
        if (!$store) {
            if (!function_exists('deflate_init')) { throw new \RuntimeException(__('Streaming compression is unavailable.', 'chada-aio')); }
            $deflater = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
            if (!$deflater) { throw new \RuntimeException(__('Streaming compression could not be started.', 'chada-aio')); }
        }
        while (!feof($input)) {
            $chunk = fread($input, CHADA_AIO_Config::STREAM_CHUNK);
            if ($chunk === false) { throw new \RuntimeException(__('A package source file could not be read completely.', 'chada-aio')); }
            if ($chunk === '') { break; }
            hash_update($hash, $chunk); hash_update($crc, $chunk); $size += strlen($chunk);
            $data = $store ? $chunk : deflate_add($deflater, $chunk, ZLIB_NO_FLUSH);
            if ($data !== '') { $this->write($data); $compressed += strlen($data); }
        }
        if (!$store) {
            $data = deflate_add($deflater, '', ZLIB_FINISH);
            if ($data !== '') { $this->write($data); $compressed += strlen($data); }
        }
        if ($size !== $expected_size || $size > CHADA_AIO_Config::MAX_ENTRY) { throw new \RuntimeException(__('A source file changed while it was archived.', 'chada-aio')); }
        $crc_value = (int) hexdec(hash_final($crc));
        $this->write(pack('V4', 0x08074b50, $crc_value, $compressed, $size));
        $end_offset = ftell($this->handle);
        if ($end_offset === false) { throw new \RuntimeException(__('The archive position could not be measured.', 'chada-aio')); }
        return ['name' => $name, 'size' => $size, 'sha256' => hash_final($hash), 'crc' => $crc_value, 'compressed_size' => $compressed,
            'offset' => $offset, 'end_offset' => $end_offset, 'method' => $method, 'time' => $time, 'date' => $date, 'bytes_read' => $size,
            'bytes_written' => $compressed + 16 + $name_length + 30];
    }

    private function write(string $data): void {
        $written = 0; $length = strlen($data);
        while ($written < $length) {
            $count = fwrite($this->handle, substr($data, $written));
            if ($count === false || $count === 0) { throw new \RuntimeException(__('The archive could not be written completely.', 'chada-aio')); }
            $written += $count;
        }
        $this->bytes_written += $length;
    }

    private function dos_time(): array {
        $time = time(); $parts = getdate($time);
        return [($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2), (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']];
    }
}
