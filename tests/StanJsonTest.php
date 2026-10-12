<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\StanJson;
use PHPUnit\Framework\Attributes\DataProvider;
final class StanJsonTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $file = StanJson::write(['N' => 2, 'flags' => [true, false], 'x' => [[1.0,2.0],[3.0,4.0]], 'empty' => []], $this->dir.'/data.json');
        self::assertSame(['N' => 2, 'flags' => [1,0], 'x' => [[1.0,2.0],[3.0,4.0]], 'empty' => []], json_decode(file_get_contents($file), true));
    }
    public function testEmptyDataIsObject(): void { $file = StanJson::write([], $this->dir.'/data.json'); self::assertSame('{}', trim(file_get_contents($file))); }
    public static function invalid(): array { return [['x' => null], ['x' => 'text'], ['x' => NAN], ['x' => INF], ['x' => [[1],[2,3]]], ['x' => [1 => 2]], ['bad name' => 1], [0 => 1]]; }
    #[DataProvider('badData')]
    public function testRejectsInvalidData(array $data): void { $this->expectException(\InvalidArgumentException::class); StanJson::write($data, $this->dir.'/bad.json'); }
    public static function badData(): array { return array_map(fn($x) => [$x], self::invalid()); }
}
