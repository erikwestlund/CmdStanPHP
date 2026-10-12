<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, NativeBuild, StanFunctions};
use PHPUnit\Framework\Attributes\Group;
#[Group('integration')]
final class StanFunctionsTest extends TestCase
{
    private const FUNCTIONS = <<<'STAN'
        functions {
          real add(real x, int y) { return x + y; }
          int iadd(int a, int b) { return a + b; }
          vector scale(data vector v, real a) { return a * v; }
          row_vector flip(row_vector r) { return reverse(r); }
          matrix outer(row_vector r, vector c) { return c * r; }
          array[] real twice(array[] real x) { return to_array_1d(2 * to_vector(x)); }
          array[,] int pairs(int n) { array[n, 2] int out; for (i in 1:n) { out[i] = {i, -i}; } return out; }
          array[] vector stack(array[] vector x, matrix m) { array[size(x) + 1] vector[rows(m)] out; for (i in 1:size(x)) { out[i] = x[i]; } out[size(x) + 1] = col(m, 1); return out; }
          complex cmul(complex a, complex b) { return a * b; }
          complex_vector cscale(complex_vector z, complex c) { return c * z; }
          complex_matrix ctrans(complex_matrix z) { return z'; }
          tuple(real, array[] int) summary(tuple(vector, int) t) { return (sum(t.1), {t.2, 2 * t.2}); }
          void shout(real x) { print("x = ", x); }
          real draw_rng(real mu) { return normal_rng(mu, 1); }
          real shifted_lpdf(real y, real mu) { return normal_lpdf(y | mu, 1); }
          real counts_lpmf(int k, real lambda) { return poisson_lpmf(k | lambda); }
          real checked(real x) { if (x < 0) { reject("x must be nonnegative but was ", x); } return sqrt(x); }
          real accumulate_lp(real x) { target += x; return x; }
          real twin(real x) { return x; }
          real twin(vector x) { return sum(x); }
        }
        STAN;
    private static ?StanFunctions $compiled = null;
    private static ?string $cache = null;
    private function functions(): StanFunctions
    {
        try { CmdStan::path(); NativeBuild::requireFfi(); } catch (\RuntimeException $e) { self::markTestSkipped('CmdStan and PHP FFI are required: '.$e->getMessage()); }
        if (self::$compiled === null) {
            self::$cache = sys_get_temp_dir().'/cmdstanphp-functions-test-'.bin2hex(random_bytes(6)); mkdir(self::$cache);
            self::$compiled = StanFunctions::compile(\CmdStan\write_stan_file(self::FUNCTIONS, self::$cache.'/functions.stan'), timeout: 600);
        }
        return self::$compiled;
    }
    public static function tearDownAfterClass(): void
    {
        if (self::$cache !== null) { array_map('unlink', glob(self::$cache.'/*') ?: []); rmdir(self::$cache); self::$cache = null; self::$compiled = null; }
    }
    public function testScalarVectorMatrixAndArrayTypes(): void
    {
        $f = $this->functions();
        self::assertSame(3.5, $f->add(1.5, 2)); self::assertSame(5, $f->iadd(2, 3)); self::assertSame(5, $f->call('iadd', 2.0, 3));
        self::assertSame([2.0, 4.0, 6.0], $f->scale([1, 2, 3], 2.0)); self::assertSame([3.0, 2.0, 1.0], $f->flip([1.0, 2.0, 3.0]));
        self::assertSame([[3.0, 6.0], [4.0, 8.0], [5.0, 10.0]], $f->outer([1, 2], [3, 4, 5])); self::assertSame([], $f->scale([], 2.0));
        self::assertSame([3.0, -4.0], $f->twice([1.5, -2])); self::assertSame([[1, -1], [2, -2], [3, -3]], $f->pairs(3)); self::assertSame([], $f->pairs(0));
        self::assertSame([[1.0, 2.0], [3.0, 4.0], [5.0, 7.0]], $f->stack([[1, 2], [3, 4]], [[5, 6], [7, 8]]));
    }
    public function testComplexAndTupleTypes(): void
    {
        $f = $this->functions();
        self::assertSame([5.0, 5.0], $f->cmul([1, 2], [3, -1])); self::assertSame([3.0, 6.0], $f->cmul(3.0, [1, 2]), 'Reals promote to complex.');
        self::assertSame([[0.0, 2.0], [-2.0, 2.0]], $f->cscale([[2, 0], [2, 2]], [0, 1]));
        self::assertSame([[[1.0, 1.0], [3.0, 3.0]], [[2.0, 2.0], [4.0, 4.0]]], $f->ctrans([[[1, 1], [2, 2]], [[3, 3], [4, 4]]]), 'Stan transposes complex matrices without conjugating.');
        self::assertSame([6.0, [4, 8]], $f->summary([[1, 2, 3], 4]));
    }
    public function testVoidPrintingNamedArgumentsAndDensities(): void
    {
        $f = $this->functions();
        $this->expectOutputString("x = 2.5\n"); self::assertNull($f->shout(2.5));
        self::assertSame(3.5, $f->add(y: 2, x: 1.5)); self::assertSame(3.5, $f->add(1.5, y: 2));
        self::assertEqualsWithDelta(-0.5 * log(2 * M_PI) - 0.5 * 0.7 ** 2, $f->shifted_lpdf(0.3, 1.0), 1e-14, '_lpdf functions return the full density, as in CmdStanR.');
        self::assertEqualsWithDelta(2 * log(1.5) - 1.5 - log(2), $f->counts_lpmf(2, 1.5), 1e-14);
    }
    public function testRngFunctionsAreSeeded(): void
    {
        $f = $this->functions();
        $a = $f->draw_rng(0.5, seed: 42); self::assertSame($a, $f->draw_rng(0.5, seed: 42)); self::assertSame($a, $f->draw_rng(mu: 0.5, seed: 42));
        self::assertNotSame($a, $f->draw_rng(0.5, seed: 43)); self::assertIsFloat($f->draw_rng(0.5)); self::assertNotSame($f->draw_rng(0.5), $f->draw_rng(0.5));
        self::assertTrue($f->functions()['draw_rng']['rng']); self::assertFalse($f->functions()['add']['rng']);
    }
    public function testMetadataSerializationAndUnsupportedFunctions(): void
    {
        $f = $this->functions(); $meta = $f->functions();
        self::assertSame(['return_type' => 'real', 'arguments' => ['x' => 'real', 'y' => 'int'], 'rng' => false, 'supported' => true, 'reason' => null], $meta['add']);
        self::assertSame(['x' => 'array[] vector', 'm' => 'matrix'], $meta['stack']['arguments']); self::assertSame('array[,] int', $meta['pairs']['return_type']);
        self::assertSame('tuple(real, array[] int)', $meta['summary']['return_type']); self::assertSame(['z' => 'complex_matrix'], $meta['ctrans']['arguments']); self::assertSame('void', $meta['shout']['return_type']);
        self::assertFalse($meta['accumulate_lp']['supported']); self::assertFalse($meta['twin']['supported']); self::assertStringContainsString('overloaded', $meta['twin']['reason']);
        $restored = unserialize(serialize($f)); self::assertSame(3.5, $restored->add(1.5, 2)); self::assertSame($f->library(), $restored->library());
        foreach (['accumulate_lp' => '_lp functions', 'twin' => 'overloaded', 'missing' => 'Unknown Stan function'] as $name => $message) {
            try { $f->call($name, 1.0); self::fail('Expected '.$name.' to be rejected.'); }
            catch (\BadMethodCallException $e) { self::assertStringContainsString($message, $e->getMessage()); }
        }
    }
    public function testArgumentAndStanErrors(): void
    {
        $f = $this->functions();
        $cases = [
            [fn() => $f->add(1.0), \InvalidArgumentException::class, 'expects 2 argument(s): add(real x, int y)'],
            [fn() => $f->add(1.0, z: 2), \InvalidArgumentException::class, 'no argument named z'],
            [fn() => $f->iadd(1.5, 2), \InvalidArgumentException::class, 'Argument 1: expected an integer'],
            [fn() => $f->scale(1.0, 2.0), \InvalidArgumentException::class, 'expected a list'],
            [fn() => $f->outer([1, 2], [[3]]), \InvalidArgumentException::class, 'Argument 2'],
            [fn() => $f->scale(['a' => 1.0], 2.0), \InvalidArgumentException::class, 'zero-indexed list'],
            [fn() => $f->summary([[1.0], 2, 3]), \InvalidArgumentException::class, 'tuple with 2 elements'],
            [fn() => $f->draw_rng(0.0, seed: -1), \InvalidArgumentException::class, 'seed'],
            [fn() => $f->checked(-1.0), \RuntimeException::class, 'x must be nonnegative but was -1'],
        ];
        foreach ($cases as [$call, $class, $message]) {
            try { $call(); self::fail('Expected '.$message); }
            catch (\RuntimeException|\InvalidArgumentException|\LogicException $e) { self::assertInstanceOf($class, $e); self::assertStringContainsString($message, $e->getMessage()); }
        }
        self::assertSame(2.0, $f->checked(4.0));
    }
    public function testModelWithoutFunctionsIsRejected(): void
    {
        $this->functions();
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('No standalone functions');
        StanFunctions::compile(\CmdStan\write_stan_file('parameters { real mu; } model { mu ~ normal(0, 1); }', $this->dir.'/plain.stan'));
    }
}
