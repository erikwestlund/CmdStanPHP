<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanGQ extends CmdStanFit
{
    public function fitted_params_files(): array { return $this->context['fitted_params_files'] ?? []; }
}
