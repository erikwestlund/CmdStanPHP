<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{DrawKernels, Process};
final class DrawKernelsTest extends TestCase
{
    public function testMissingNativeFunctionsAreNamedInTheError(): void
    {
        $code = 'require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; '
            .'try { CmdStan\\DrawKernels::require(); exit(2); } '
            .'catch (RuntimeException $e) { echo json_encode(["missing" => CmdStan\\DrawKernels::missing(), "message" => $e->getMessage()]); }';
        $result = Process::run([PHP_BINARY, '-n', '-r', $code]);
        $error = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(DrawKernels::FUNCTIONS, $error['missing']);
        foreach (DrawKernels::FUNCTIONS as $function) { self::assertStringContainsString($function, $error['message']); }
    }
}
