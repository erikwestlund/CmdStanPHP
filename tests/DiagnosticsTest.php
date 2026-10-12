<?php
declare(strict_types=1);
namespace CmdStan\Tests;
use CmdStan\Diagnostics;
final class DiagnosticsTest extends TestCase
{
    private function chains(): array { return [[0,0,...range(1,15),20,20,30], [...range(1,16),20,20,20,30]]; }
    public function testInclusiveTailIndicatorsMatchPosteriorWithTies(): void
    {
        // Reference values from posterior 1.7.1, independently calculated in R.
        self::assertEqualsWithDelta(17.307692307692296, Diagnostics::essTail($this->chains()), 1e-12);
    }
    public function testOddChainsDropMiddleObservationWhenSplitting(): void
    {
        $chains = array_map(fn($chain) => array_slice($chain, 1), $this->chains());
        self::assertEqualsWithDelta(15.945317840054686, Diagnostics::essTail($chains), 1e-12);
    }
    public function testInvalidAndDegenerateChainsAreNan(): void
    {
        foreach ([[], [[1,1,1],[1,1,1]], [[1,2]], [[1,2,3],[1,2]], [[1,2,INF,4,5,6]], [[1,2,NAN,4,5,6]]] as $chains) { self::assertTrue(is_nan(Diagnostics::essTail($chains))); }
    }
}
