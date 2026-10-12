<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanVB extends CmdStanFit
{
    public function lp_approx(): array { return $this->approximateLp('lp_approx__'); }
    public function variational_mean(): array { return $this->parsed()[0]['estimate'] ?? []; }
}
