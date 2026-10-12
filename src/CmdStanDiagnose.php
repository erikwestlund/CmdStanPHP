<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanDiagnose extends CmdStanFit
{
    public function gradients(): string
    {
        return $this->results !== [] ? implode("\n", $this->output()) : implode("\n", $this->parsed()[0]['comments']);
    }
    public function lp(): float
    {
        foreach ($this->parsed()[0]['comments'] as $comment) {
            if (preg_match('/^Log probability\s*=\s*(.*)$/', $comment, $match)) { return (float)$match[1]; }
        }
        return NAN;
    }
}
