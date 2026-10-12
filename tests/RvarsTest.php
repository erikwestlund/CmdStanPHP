<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStanFit, Rvars};
final class RvarsTest extends TestCase
{
    public function testShapesFollowMaximumIndicesAndFillGapsWithNan(): void
    {
        $rows = [['theta' => 0.1, 'x[1,1]' => 1, 'x[2,3]' => 2, 'lp__' => -1, '.chain' => 1, '.iteration' => 1, '.draw' => 1], ['theta' => 0.2, 'x[1,1]' => 3, 'x[2,3]' => 4, 'lp__' => -2, '.chain' => 2, '.iteration' => 1, '.draw' => 2]];
        $rvars = Rvars::fromDrawsDf($rows);
        self::assertSame(['theta', 'x', 'lp__'], array_keys($rvars));
        self::assertSame(['dims' => [], 'nchains' => 2, 'ndraws' => 2, 'draws' => [0.1, 0.2]], $rvars['theta']);
        self::assertSame([2, 3], $rvars['x']['dims']); self::assertSame([1.0, 3.0], $rvars['x']['draws'][0][0]); self::assertSame([2.0, 4.0], $rvars['x']['draws'][1][2]);
        self::assertNan($rvars['x']['draws'][0][1][0]);
        self::assertSame(0.2, Rvars::draw($rvars['theta'], 1));
        $draw = Rvars::draw($rvars['x'], 1); self::assertSame(3.0, $draw[0][0]); self::assertSame(4.0, $draw[1][2]); self::assertNan($draw[1][0]);
    }
    public function testDrawsArrayIsFlattenedChainMajor(): void
    {
        $rvars = Rvars::fromDrawsArray([[['y[1]' => 1, 'y[2]' => 2], ['y[1]' => 10, 'y[2]' => 20]], [['y[1]' => 3, 'y[2]' => 4], ['y[1]' => 30, 'y[2]' => 40]]]);
        self::assertSame(['dims' => [2], 'nchains' => 2, 'ndraws' => 4, 'draws' => [[1.0, 3.0, 10.0, 30.0], [2.0, 4.0, 20.0, 40.0]]], $rvars['y']);
        self::assertSame([10.0, 20.0], Rvars::draw($rvars['y'], 2));
    }
    public function testFromFit(): void
    {
        $fit = CmdStanFit::fromCsv([$this->csv('a.csv'), $this->csv('b.csv')]); $rvars = Rvars::fromFit($fit, ['theta', 'x']);
        self::assertSame(['theta', 'x'], array_keys($rvars)); self::assertSame([2], $rvars['x']['dims']); self::assertSame([3.0, 4.0, 3.0, 4.0], $rvars['x']['draws'][0]); self::assertSame(2, $rvars['x']['nchains']);
    }
    public function testInvalidNames(): void
    {
        self::assertSame(['x', [1, 12]], Rvars::parseName('x[1, 12]')); self::assertSame(['a.b', []], Rvars::parseName('a.b'));
        foreach (['x[0]', 'x[a]', 'x[1', 'x[1]]'] as $name) {
            try { Rvars::parseName($name); self::fail('Expected exception for '.$name); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class); Rvars::fromDrawsDf([['x[1]' => 1, 'x[1,2]' => 2]]);
    }
    public function testDrawOutOfRange(): void { $this->expectException(\OutOfRangeException::class); Rvars::draw(Rvars::fromDrawsDf([['a' => 1]])['a'], 1); }
}
