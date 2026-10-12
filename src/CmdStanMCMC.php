<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanMCMC extends CmdStanFit
{
    /** PSIS-LOO as in CmdStanR's loo() (see Loo::loo() for the result shape); moment matching is not implemented. */
    public function loo(string $variables = 'log_lik', array|float|bool|null $r_eff = true, bool $moment_match = false): array
    {
        if ($moment_match) { throw new \InvalidArgumentException('Moment matching is not implemented.'); }
        return Loo::fromFit($this, $variables, $r_eff);
    }
    public function waic(string $variables = 'log_lik'): array { return Loo::waicFromFit($this, $variables); }
}
