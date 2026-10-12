<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Builds shared libraries from stanc output with CmdStan's own toolchain and loads them through PHP FFI.
 * Libraries are cached next to the Stan file as <name>_<kind>-<fingerprint>.<ext>; the fingerprint covers
 * the generated C++, the shims, the CmdStan path/version and make/local, so stale builds are never reused.
 */
final class NativeBuild
{
    private static array $ffi = [];
    public static function resources(): string { return dirname(__DIR__).'/resources'; }
    public static function extension(): string { return match (PHP_OS_FAMILY) { 'Darwin' => '.dylib', 'Windows' => '.dll', default => '.so' }; }
    /** FFI must be loaded and usable: ffi.enable=true, or ffi.enable=preload (the default) from the CLI. */
    public static function requireFfi(): void
    {
        if (!extension_loaded('ffi') || !class_exists(\FFI::class)) { throw new \RuntimeException('In-process Stan methods require the PHP FFI extension.'); }
        $mode = strtolower((string)ini_get('ffi.enable'));
        if (in_array($mode, ['0', '', 'off', 'false', 'no'], true)) { throw new \RuntimeException('In-process Stan methods require FFI; set ffi.enable=true.'); }
        if ($mode === 'preload' && PHP_SAPI !== 'cli') { throw new \RuntimeException('ffi.enable=preload only permits FFI in the CLI; set ffi.enable=true to use in-process Stan methods under '.PHP_SAPI.'.'); }
    }
    public static function ffi(string $header, string $library): \FFI
    {
        self::requireFfi();
        $key = $library."\0".$header;
        if (isset(self::$ffi[$key])) { return self::$ffi[$key]; }
        if (!is_file($library)) { throw new \RuntimeException('Native library not found: '.$library); }
        try { return self::$ffi[$key] = \FFI::cdef($header, $library); }
        catch (\FFI\Exception $e) { throw new \RuntimeException('Cannot load native library '.$library.': '.$e->getMessage(), 0, $e); }
    }
    /** Translate a Stan file with stanc and return the generated C++. */
    public static function stanc(string $stanFile, array $args = [], ?float $timeout = null): string
    {
        $dir = self::temporaryDirectory();
        try {
            Process::run(array_merge([CmdStan::path().'/bin/stanc'.CmdStan::suffix(), '--o='.$dir.'/out.hpp'], $args, [$stanFile]), timeout: $timeout);
            return (string)file_get_contents($dir.'/out.hpp');
        } finally { self::remove($dir); }
    }
    /** Map CmdStanModel-style stanc options (['include-paths' => [...], 'O1' => true]) to arguments. */
    public static function stancArgs(array $options): array
    {
        $args = [];
        foreach ($options as $name => $value) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', (string)$name)) { throw new \InvalidArgumentException('Invalid stanc option.'); }
            if ($value === false || $value === null) { continue; }
            if (in_array($name, ['o', 'standalone-functions', 'info', 'auto-format', 'print-cpp', 'debug-generate-data'], true)) { throw new \InvalidArgumentException('stanc option not allowed here: '.$name); }
            $args[] = $value === true ? '--'.$name : '--'.$name.'='.(is_array($value) ? implode(',', $value) : Options::scalar($value));
        }
        return $args;
    }
    /**
     * Compile $cpp (which may include "model.hpp", written from $hpp) against the given resource headers
     * and return the cached library path for $stanFile.
     */
    public static function library(string $stanFile, string $kind, string $hpp, string $cpp, array $resources, ?string $outputDir = null, ?float $timeout = null): string
    {
        $stanFile = realpath($stanFile) ?: throw new \InvalidArgumentException('Stan file not found: '.$stanFile);
        $dir = $outputDir === null ? dirname($stanFile) : (realpath($outputDir) ?: throw new \InvalidArgumentException('Output directory not found: '.$outputDir));
        if (!is_writable($dir)) { throw new \RuntimeException('Cannot write native library to '.$dir.'; pass an output directory.'); }
        $local = CmdStan::path().'/make/local';
        $parts = [1, CmdStan::path(), CmdStan::version(), PHP_OS_FAMILY, php_uname('m'), $kind, hash('sha256', $hpp), hash('sha256', $cpp), is_file($local) ? hash_file('sha256', $local) : null];
        foreach (array_merge($resources, ['native.mk']) as $file) { $parts[] = hash_file('sha256', self::resources().'/'.$file); }
        $name = pathinfo($stanFile, PATHINFO_FILENAME).'_'.$kind;
        $library = $dir.'/'.$name.'-'.substr(hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)), 0, 16).self::extension();
        if (is_file($library)) { return $library; }
        $build = self::temporaryDirectory();
        try {
            if (file_put_contents($build.'/model.hpp', $hpp) === false || file_put_contents($build.'/source.cpp', $cpp) === false) { throw new \RuntimeException('Cannot write native build sources.'); }
            $tmp = $dir.'/.'.$name.'-'.bin2hex(random_bytes(6)).self::extension();
            foreach ([$build, $tmp, self::resources()] as $path) {
                if (preg_match('/[\s$#;:%"\'`=*?|<>()]/', PHP_OS_FAMILY === 'Windows' ? (string)preg_replace('/^[A-Za-z]:/', '', $path) : $path)) { throw new \InvalidArgumentException('Native builds need paths without whitespace or make metacharacters: '.$path); }
            }
            try {
                Process::run(['make', '-C', CmdStan::path(), '-f', 'makefile', '-f', self::resources().'/native.mk', 'CMDSTANPHP_SRC='.$build.'/source.cpp', 'CMDSTANPHP_OUT='.$tmp, 'cmdstanphp-native'], timeout: $timeout);
                if (!is_file($tmp)) { throw new \RuntimeException('Native build did not produce a library.'); }
                foreach (glob($dir.'/'.$name.'-*'.self::extension()) ?: [] as $stale) {
                    if (preg_match('/-[0-9a-f]{16}\.[a-z]+$/D', $stale)) { @unlink($stale); }
                }
                if (!rename($tmp, $library)) { throw new \RuntimeException('Cannot install native library: '.$library); }
            } finally { if (is_file($tmp)) { unlink($tmp); } }
        } finally { self::remove($build); }
        return $library;
    }
    /** Copy a C string returned by the library and release it with cmdstanphp_free. */
    public static function takeString(\FFI $ffi, ?\FFI\CData $pointer): ?string
    {
        if ($pointer === null || \FFI::isNull($pointer)) { return null; }
        try { return \FFI::string($pointer); } finally { $ffi->cmdstanphp_free($pointer); }
    }
    public static function doubles(\FFI $ffi, array $values): \FFI\CData
    {
        $buffer = $ffi->new('double['.max(1, count($values)).']');
        foreach (array_values($values) as $i => $value) { $buffer[$i] = (float)$value; }
        return $buffer;
    }
    private static function temporaryDirectory(): string
    {
        $dir = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/cmdstanphp-native-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('Cannot create build directory.'); }
        return $dir;
    }
    private static function remove(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); }
        if (is_dir($dir)) { rmdir($dir); }
    }
}
