<?php
declare(strict_types=1);
namespace CmdStan;
final class ProcessException extends \RuntimeException
{
    public function __construct(public readonly array $command, public readonly int $exitCode, public readonly string $stdout, public readonly string $stderr)
    {
        parent::__construct('CmdStan command failed (exit '.$exitCode.'): '.implode(' ', $command)."\n".$stderr.$stdout, $exitCode);
    }
}
