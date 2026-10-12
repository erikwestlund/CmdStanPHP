<?php
declare(strict_types=1);
namespace CmdStan;
final class Installer
{
    /**
     * Installs CmdStan from an official release (version, or the latest release when no source is given), an https .tar.gz
     * release_url, or a local release_file tarball. The installation directory is the archive's top-level directory.
     * With overwrite, an existing installation is moved aside and only removed after the new build succeeds.
     * cpp_options are written to make/local (append) before building.
     */
    public static function install(?string $version = null, ?string $dir = null, ?int $cores = null, ?float $timeout = null, bool $overwrite = false, ?string $release_url = null, ?string $release_file = null, array $cpp_options = [], bool $check_toolchain = true): string
    {
        $cores ??= CmdStan::option('mc.cores', 1);
        if ($cores < 1) { throw new \InvalidArgumentException('cores must be positive.'); }
        if (count(array_filter([$version, $release_url, $release_file], fn($x) => $x !== null)) > 1) { throw new \InvalidArgumentException('Specify only one of version, release_url and release_file.'); }
        if ($version !== null && !preg_match('/^2\.\d+\.\d+$/D', $version)) { throw new \InvalidArgumentException('Provide a stable CmdStan 2.x version.'); }
        if ($release_url !== null && (!str_starts_with($release_url, 'https://') || !str_ends_with($release_url, '.tar.gz') || preg_match('/[\s\x00-\x1f]/', $release_url))) { throw new \InvalidArgumentException('release_url must be an https URL of a .tar.gz archive.'); }
        if ($release_file !== null && (!is_file($release_file) || !str_ends_with($release_file, '.tar.gz'))) { throw new \InvalidArgumentException('release_file must be an existing .tar.gz archive.'); }
        foreach ($cpp_options as $name => $value) { self::makeLocalLine($name, $value); }
        $dir ??= (getenv('HOME') ?: getenv('USERPROFILE')).'/.cmdstan';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new \RuntimeException('Cannot create installation directory.'); }
        $dir = realpath($dir);
        // Fail before downloading when the official release target is already known to exist.
        if ($version !== null && !$overwrite && file_exists($dir.'/cmdstan-'.$version)) { throw new \RuntimeException('Installation already exists: '.$dir.'/cmdstan-'.$version.'. Use overwrite: true to replace it.'); }
        if ($check_toolchain) { CmdStan::checkToolchain($dir); }
        $staging = $dir.'/.cmdstanphp-install-'.bin2hex(random_bytes(8)); mkdir($staging, 0700);
        $target = null; $backup = null; $created = false;
        try {
            $archive = $staging.'/release.tar.gz';
            if ($release_file !== null) {
                if (!copy($release_file, $archive)) { throw new \RuntimeException('Cannot copy release_file.'); }
            } else {
                if ($release_url === null) { $version ??= self::latestVersion($timeout); }
                $url = $release_url ?? 'https://github.com/stan-dev/cmdstan/releases/download/v'.$version.'/cmdstan-'.$version.'.tar.gz';
                Process::run(['curl', '--fail', '--location', '--retry', '3', '--proto', '=https', '--proto-redir', '=https', '--output', $archive, $url], timeout: $timeout);
            }
            $top = self::archiveRoot($archive, $timeout);
            if ($version !== null && $release_url === null && $top !== 'cmdstan-'.$version) { throw new \RuntimeException('Release archive does not contain cmdstan-'.$version.'.'); }
            $target = $dir.'/'.$top;
            if (file_exists($target) && !$overwrite) { throw new \RuntimeException('Installation already exists: '.$target.'. Use overwrite: true to replace it.'); }
            $extract = $staging.'/extract'; mkdir($extract, 0700);
            Process::run(['tar', '-xzf', $archive, '-C', $extract], timeout: $timeout);
            if (!is_file($extract.'/'.$top.'/makefile')) { throw new \RuntimeException('Release archive has no makefile.'); }
            if (file_exists($target)) {
                $backup = $dir.'/.cmdstanphp-previous-'.bin2hex(random_bytes(8));
                if (!rename($target, $backup)) { throw new \RuntimeException('Cannot move existing installation aside.'); }
            }
            if (!rename($extract.'/'.$top, $target)) { throw new \RuntimeException('Cannot move extracted installation.'); }
            $created = true;
            if ($cpp_options !== []) { self::makeLocal($target, $cpp_options, true); }
            // TBB runtime paths are embedded in binaries; build at the final location.
            Process::run(['make', '-C', $target, '-j'.$cores, 'build'], timeout: $timeout);
            CmdStan::setPath($target);
            $created = false; [$previous, $backup] = [$backup, null];
            if ($previous !== null) { try { self::removeDirectory($previous); } catch (\Throwable) {} }
            return $target;
        } catch (\Throwable $error) {
            if ($created) { self::removeDirectory($target); }
            if ($backup !== null && !file_exists($target)) { rename($backup, $target); }
            throw $error;
        } finally {
            self::removeDirectory($staging);
        }
    }
    /** Lists the archive and returns its single top-level directory, rejecting absolute paths, '..' and links escaping it. */
    public static function archiveRoot(string $archive, ?float $timeout = null): string
    {
        $entries = array_values(array_filter(explode("\n", Process::run(['tar', '-tzf', $archive], timeout: $timeout)['stdout']), fn($e) => $e !== ''));
        $verbose = array_values(array_filter(explode("\n", Process::run(['tar', '-tvzf', $archive], timeout: $timeout)['stdout']), fn($e) => $e !== ''));
        if ($entries === [] || count($verbose) !== count($entries)) { throw new \RuntimeException('Cannot list release archive.'); }
        $top = explode('/', preg_replace('~^\./~', '', $entries[0]))[0];
        if (!preg_match('/^cmdstan-[A-Za-z0-9._-]+$/D', $top)) { throw new \RuntimeException('Release archive must contain a single cmdstan-* directory.'); }
        foreach ($entries as $i => $raw) {
            $entry = preg_replace('~^\./~', '', $raw);
            if (($entry !== $top && !str_starts_with($entry, $top.'/')) || preg_match('~(^|/)\.\.(/|$)~', $entry)) { throw new \RuntimeException('Unsafe path in release archive.'); }
            $line = $verbose[$i];
            if ($line[0] !== 'l' && $line[0] !== 'h') { continue; }
            $at = strpos($line, ' '.rtrim($raw, '/').($line[0] === 'l' ? ' -> ' : ' link to '));
            if ($at === false) { throw new \RuntimeException('Cannot verify link in release archive.'); }
            $target = substr($line, $at + strlen(' '.rtrim($raw, '/')) + ($line[0] === 'l' ? 4 : 9));
            $base = $line[0] === 'l' ? explode('/', dirname($entry)) : [];
            if (str_starts_with($target, '/') || !self::contained($base, $target, $top)) { throw new \RuntimeException('Unsafe link in release archive.'); }
        }
        return $top;
    }
    private static function contained(array $parts, string $target, string $top): bool
    {
        foreach (explode('/', preg_replace('~^\./~', '', $target)) as $part) {
            if ($part === '..') { if (array_pop($parts) === null) { return false; } }
            elseif ($part !== '.' && $part !== '') { $parts[] = $part; }
        }
        return ($parts[0] ?? null) === $top;
    }
    private static function latestVersion(?float $timeout): string
    {
        $json = Process::run(['curl', '--fail', '--location', '--silent', '--proto', '=https', '--proto-redir', '=https', 'https://api.github.com/repos/stan-dev/cmdstan/releases/latest'], timeout: $timeout)['stdout'];
        $tag = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['tag_name'] ?? '';
        if (!preg_match('/^v(2\.\d+\.\d+)$/D', $tag, $m)) { throw new \RuntimeException('Cannot determine the latest CmdStan release.'); }
        return $m[1];
    }
    private static function makeLocalLine(int|string $name, mixed $value): string
    {
        $value = $value === true ? 'true' : ($value === false ? 'false' : Options::scalar($value));
        if (preg_match('/[\r\n]/', $value)) { throw new \InvalidArgumentException('make/local values cannot contain newlines.'); }
        if (is_int($name)) { return $value; }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Invalid make option name.'); }
        return strtoupper($name).'='.$value;
    }
    /** CmdStanR's cmdstan_make_local(): optionally writes NAME=value (or unnamed raw) lines, then returns the current lines. */
    public static function makeLocal(?string $dir = null, array $cpp_options = [], bool $append = true): array
    {
        $dir ??= CmdStan::path();
        $file = $dir.'/make/local';
        if ($cpp_options !== [] || !$append) {
            $lines = array_map(self::makeLocalLine(...), array_keys($cpp_options), array_values($cpp_options));
            if (!is_dir($dir.'/make')) { throw new \InvalidArgumentException('Not a CmdStan directory: '.$dir); }
            if (file_put_contents($file, $lines === [] ? '' : implode("\n", $lines)."\n", ($append ? FILE_APPEND : 0) | LOCK_EX) === false) { throw new \RuntimeException('Cannot write make/local.'); }
        }
        if (!is_file($file)) { return []; }
        $content = trim((string)file_get_contents($file));
        return $content === '' ? [] : array_map('trim', explode("\n", $content));
    }
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) { return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($dir);
    }
    public static function rebuild(?int $cores = null, ?float $timeout = null): void
    {
        $cores ??= CmdStan::option('mc.cores', 1);
        if ($cores < 1) { throw new \InvalidArgumentException('cores must be positive.'); }
        Process::run(['make', '-C', CmdStan::path(), 'clean-all'], timeout: $timeout);
        Process::run(['make', '-C', CmdStan::path(), '-j'.$cores, 'build'], timeout: $timeout);
    }
}
