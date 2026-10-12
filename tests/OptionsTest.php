<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\Options;
use PHPUnit\Framework\Attributes\DataProvider;
final class OptionsTest extends TestCase
{
    public function testSamplerArgumentNesting(): void
    {
        self::assertSame(['sample','num_samples=10','num_warmup=20','algorithm=hmc','metric=dense_e','stepsize=0.1','engine=nuts','max_depth=12','adapt','engaged=0','delta=0.9'], Options::methodArgs('sample', ['iter_sampling' => 10, 'iter_warmup' => 20, 'metric' => 'dense_e', 'step_size' => 0.1, 'max_treedepth' => 12, 'adapt_engaged' => false, 'adapt_delta' => 0.9]));
    }
    public function testFixedParameterHasNoHmcOptions(): void { self::assertSame(['sample','algorithm=fixed_param'], Options::methodArgs('sample', ['fixed_param' => true])); }
    public function testPathfinderDrawTranslation(): void { self::assertSame(['pathfinder','num_draws=20','num_psis_draws=30'], Options::methodArgs('pathfinder', ['single_path_draws' => 20, 'draws' => 30])); }
    public function testVariationalAdaptationTranslation(): void { self::assertSame(['variational','output_samples=20','adapt','engaged=0','iter=10'], Options::methodArgs('variational', ['draws' => 20, 'adapt_engaged' => false, 'adapt_iter' => 10])); }
    #[DataProvider('badOptions')]
    public function testValidation(array $options): void { $this->expectException(\InvalidArgumentException::class); Options::validate('sample', $options); }
    public static function badOptions(): array
    {
        return array_map(fn($x) => [$x], [['unknown' => 1], ['chains' => 0], ['chains' => 1.5], ['iter_warmup' => -1], ['save_warmup' => 1], ['seed' => -1], ['seed' => 4294967296], ['seed' => '123'], ['step_size' => NAN], ['adapt_delta' => 1], ['metric' => 'invalid'], ['sig_figs' => 19], ['opencl_ids' => [1]], ['metric_file' => 'x', 'inv_metric' => [1]]]);
    }
}
