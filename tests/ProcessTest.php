<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{Process, ProcessException};
final class ProcessTest extends TestCase
{
    public function testArgumentsAndEnvironmentWithoutShell(): void
    {
        $r = Process::run([PHP_BINARY, '-r', 'echo $argv[1].getenv("CMDSTAN_TEST");', '$(touch nope); `echo x`'], env: ['CMDSTAN_TEST' => ' ok']);
        self::assertSame('$(touch nope); `echo x` ok', $r['stdout']);
        self::assertSame(0, $r['exit_code']);
    }
    public function testDrainsBothPipes(): void
    {
        $r = Process::run([PHP_BINARY, '-r', 'for ($i=0;$i<1000;$i++) { fwrite(STDOUT,str_repeat("a",1024)); fwrite(STDERR,str_repeat("b",1024)); }']);
        self::assertSame(1024000, strlen($r['stdout'])); self::assertSame(1024000, strlen($r['stderr']));
    }
    public function testErrorRetainsOutput(): void
    {
        try { Process::run([PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR,"err"); exit(7);']); self::fail('Expected failure'); }
        catch (ProcessException $e) { self::assertSame(7, $e->exitCode); self::assertSame('out', $e->stdout); self::assertSame('err', $e->stderr); }
    }
    public function testParallelOrderAndConcurrency(): void
    {
        $jobs = []; for ($i = 0; $i < 4; ++$i) { $jobs[] = ['command' => [PHP_BINARY, '-r', 'usleep(150000); echo $argv[1];', (string)$i]]; }
        $r = Process::pool($jobs, 2); self::assertSame(['0','1','2','3'], array_column($r, 'stdout'));
    }
    public function testTimeout(): void { $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('timed out'); Process::run([PHP_BINARY, '-r', 'sleep(5);'], timeout: 0.05); }
    public function testFailureCancelsOtherJobs(): void
    {
        $start = microtime(true);
        try { Process::pool([['command' => [PHP_BINARY, '-r', 'exit(5);']], ['command' => [PHP_BINARY, '-r', 'sleep(5);']]], 2); self::fail(); }
        catch (ProcessException $e) { self::assertSame(5, $e->exitCode); self::assertLessThan(2, microtime(true) - $start); }
    }
}
