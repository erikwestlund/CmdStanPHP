<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, Figure, PhizFigure};
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use function CmdStan\{mcmc_trace, mcmc_hist, mcmc_dens, mcmc_dens_overlay, mcmc_intervals, mcmc_rank_hist, mcmc_scatter, mcmc_acf, pareto_k_plot};
/**
 * Figure::toPhiz(). phiz is optional (PHP 8.5): these tests load it from PHIZ_AUTOLOAD, the vendor/autoload.php of a phiz
 * checkout, and skip otherwise, e.g. PHIZ_AUTOLOAD=../phiz/vendor/autoload.php php8.5 vendor/bin/phpunit tests/PhizFigureTest.php
 */
final class PhizFigureTest extends TestCase
{
    private static function phiz(): bool
    {
        if (!class_exists(PhizFigure::PLOT_CLASS) && is_string($path = getenv('PHIZ_AUTOLOAD')) && is_file($path) && PHP_VERSION_ID >= 80500) { require $path; }
        return class_exists(PhizFigure::PLOT_CLASS);
    }
    private static function draws(): array
    {
        $draws = []; $state = [0.0, 0.5];
        for ($i = 0; $i < 200; ++$i) {
            $iteration = [];
            foreach ([0, 1] as $c) { $state[$c] = 0.6 * $state[$c] + sin(1.7 * $i + 2.3 * $c) * 0.8; $iteration[] = ['mu' => $state[$c], 'sigma' => exp($state[$c] / 4), 'beta[1]' => $state[$c] + 1, 'beta[2]' => 2 - $state[$c]]; }
            $draws[] = $iteration;
        }
        return $draws;
    }
    /** @return array<string, Figure> */
    private static function figures(): array
    {
        $d = self::draws();
        $divergent = new Figure('scatter', 'sigma against mu', array_map(fn($i) => ['x' => sin($i), 'y' => cos($i), 'divergent' => $i % 9 === 0], range(0, 49)), ['x_variable' => 'mu', 'y_variable' => 'sigma', 'divergences' => true]);
        return ['trace' => mcmc_trace($d, 'mu'), 'hist' => mcmc_hist($d, 'mu'), 'dens' => mcmc_dens($d, 'mu'), 'overlay' => mcmc_dens_overlay($d, 'mu'), 'intervals' => mcmc_intervals($d), 'rank' => mcmc_rank_hist($d, 'mu'), 'scatter' => mcmc_scatter($d, 'mu', 'sigma'), 'divergent' => $divergent, 'acf' => mcmc_acf($d, 'mu', 10), 'pareto' => pareto_k_plot(['diagnostics' => ['pareto_k' => [0.1, 0.8, 1.3]], 'k_threshold' => 0.7])];
    }
    public function testToPhizNeedsPhiz(): void
    {
        if (class_exists(PhizFigure::PLOT_CLASS)) { self::markTestSkipped('phiz is loaded.'); }
        $this->expectExceptionMessage('composer require erikwestlund/phiz'); mcmc_trace(self::draws(), 'mu')->toPhiz();
    }
    #[RunInSeparateProcess]
    public function testEveryKindBuildsAPhizPlotFromTheFigureRows(): void
    {
        if (!self::phiz()) { self::markTestSkipped('phiz is not available; set PHIZ_AUTOLOAD to a phiz checkout\'s vendor/autoload.php (PHP 8.5).'); }
        foreach (self::figures() as $name => $figure) {
            $plot = $figure->toPhiz(400, 200);
            self::assertInstanceOf(PhizFigure::PLOT_CLASS, $plot, $name);
            $spec = $plot->toArray();
            self::assertSame('https://vega.github.io/schema/vega-lite/v6.json', $spec['$schema'], $name);
            self::assertSame($figure->data, $plot->data->rows, $name.': phiz draws the figure rows unchanged');
            $title = is_array($spec['title']) ? $spec['title']['text'] : $spec['title'];
            self::assertSame($figure->title, $title, $name);
        }
        $divergent = self::figures()['divergent']->toPhiz()->toArray();
        self::assertSame('red: 6 divergent transitions', $divergent['title']['subtitle']);
        $rank = self::figures()['rank']->toPhiz(400, 200)->toArray();
        self::assertSame(['row' => 'chain'], array_map(fn($def) => $def['field'], $rank['facet'])); self::assertSame(100, $rank['spec']['height']);
    }
    #[RunInSeparateProcess]
    public function testThinningAndSizesFollowTheVegaLiteRenderer(): void
    {
        if (!self::phiz()) { self::markTestSkipped('phiz is not available.'); }
        $trace = mcmc_trace(self::draws(), 'mu');
        self::assertSame($trace->vegaLite(max_rows: 100)['data']['values'], $trace->toPhiz(max_rows: 100)->data->rows);
        try { $trace->toPhiz(10, 10); self::fail('toPhiz() should reject tiny sizes.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('width >= 50', $e->getMessage()); }
        foreach ([0, -5] as $max_rows) {
            foreach ([$trace, mcmc_scatter(self::draws(), 'mu', 'sigma')] as $figure) {
                try { $figure->toPhiz(max_rows: $max_rows); self::fail('toPhiz() should reject max_rows '.$max_rows.'.'); } catch (\InvalidArgumentException $e) { self::assertSame('max_rows must be positive.', $e->getMessage()); }
            }
        }
    }
    public function testVegaLiteStillRejectsNonPositiveMaxRows(): void
    {
        $this->expectExceptionMessage('max_rows must be positive.'); mcmc_trace(self::draws(), 'mu')->vegaLite(max_rows: 0);
    }
    #[RunInSeparateProcess]
    public function testEveryKindRendersToSvgWithPhiz(): void
    {
        if (!self::phiz()) { self::markTestSkipped('phiz is not available.'); }
        if (!\Phiz\Export::available()) { self::markTestSkipped('vl-convert is not installed.'); }
        foreach (self::figures() as $name => $figure) { self::assertStringStartsWith('<svg', $figure->toPhiz()->svg(), $name); }
    }
}
