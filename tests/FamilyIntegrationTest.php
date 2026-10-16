<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\{CmdStan, CmdStanModel, StanData};
use PHPUnit\Framework\Attributes\Group;
use function CmdStan\write_stan_file;
#[Group('integration')]
final class FamilyIntegrationTest extends TestCase
{
    private const PROGRAM = <<<'STAN'
data {
  int<lower=0> N;
  int<lower=0> K;
  matrix[N, K] X;
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> school_id;
}
parameters {
  real alpha;
  vector[K] beta;
  vector[J] z;
  real<lower=0> tau;
  real<lower=0> sigma;
}
model {
  alpha ~ normal(0, 5); beta ~ normal(0, 5); z ~ std_normal(); tau ~ normal(0, 1); sigma ~ normal(0, 2);
  y ~ normal(alpha + X * beta + tau * z[school_id], sigma);
}
STAN;
    private function model(string $code, string $name): CmdStanModel
    {
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $dir = sys_get_temp_dir().'/cmdstanphp-family-models'; if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        $model = new CmdStanModel(write_stan_file($code, $dir.'/'.$name.'.stan')); $model->compile(); return $model;
    }
    public function testHierarchicalRegressionFromColumnsAndDrawsColumns(): void
    {
        mt_srand(7); $x = []; $y = []; $school = [];
        for ($i = 0; $i < 60; ++$i) { $school[] = ['north', 'south', 'east'][$i % 3]; $x[] = $i / 30 - 1; $y[] = 1.0 + 2.0 * $x[$i] + ['north' => 0.5, 'south' => -0.5, 'east' => 0.0][$school[$i]] + (mt_rand() / mt_getrandmax() - 0.5) * 0.4; }
        $data = StanData::fromColumns(['y' => $y, 'x' => $x, 'school' => $school], 'y', ['x'], 'school');
        self::assertSame(['east', 'north', 'south'], $data->levels['school_id']);
        $fit = $this->model(self::PROGRAM, 'family_hier')->sample(data: $data, chains: 2, iter_warmup: 300, iter_sampling: 300, seed: 11, adapt_delta: 0.95, show_messages: false, refresh: 0, output_dir: $this->dir, diagnostics: null);
        $columns = $fit->drawsColumns(['alpha', 'beta']);
        self::assertSame(['alpha', 'beta[1]', '.chain', '.iteration', '.draw'], array_keys($columns)); self::assertCount(600, $columns['beta[1]']);
        self::assertEqualsWithDelta(2.0, array_sum($columns['beta[1]']) / 600, 0.25);
        $summary = $fit->summaryColumns('beta'); self::assertSame(['beta[1]'], $summary['variable']); self::assertLessThan(1.05, $summary['rhat'][0]);
    }
    public function testTableDataRunsBernoulli(): void
    {
        $table = new class { public function toColumns(): array { return ['y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1]]; } };
        $model = new CmdStanModel(__DIR__.'/../examples/bernoulli.stan');
        try { CmdStan::path(); } catch (\RuntimeException) { self::markTestSkipped('Set CMDSTAN to run integration tests.'); }
        $fit = $model->compile()->optimize(data: $table, seed: 1, show_messages: false, output_dir: $this->dir);
        self::assertEqualsWithDelta(0.2, $fit->mle()['theta'], 1e-3);
    }
}
