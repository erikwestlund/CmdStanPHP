<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Builds phiz plots (https://github.com/erikwestlund/phiz) from Figure data, for Figure::toPhiz(). phiz is optional and
 * needs PHP 8.5; this class only references it by name, so CmdStanPHP still loads on PHP 8.2 without it. The rows are
 * Figure::$data as computed by Plot, so the statistics (and the R comparisons) are unchanged; phiz draws them with
 * identity layers in Vega-Lite 6.
 */
final class PhizFigure
{
    public const PLOT_CLASS = 'Phiz\\Plot';
    private const DIVERGENT = '#d62728';
    /** A Phiz\Plot for the figure; sizes are in pixels, per panel for the per-chain kinds. */
    public static function build(Figure $figure, int $width = 480, int $height = 240, ?int $max_rows = null): object
    {
        if (!class_exists(self::PLOT_CLASS)) { throw new \RuntimeException('toPhiz() needs the phiz package (composer require erikwestlund/phiz; it requires PHP 8.5). Without it, use vegaLite(), html(), svg(), or png().'); }
        if ($width < 50 || $height < 30) { throw new \InvalidArgumentException('phiz figures need width >= 50 and height >= 30 pixels.'); }
        $plot = self::PLOT_CLASS; $rows = VegaLite::thin($figure, $max_rows); $m = $figure->meta;
        $p = match ($figure->kind) {
            'trace' => $plot::of($rows)->aes(x: 'iteration', y: 'value', color: 'chain:N')->line(linewidth: 0.8, alpha: 0.8)->labs(y: $m['variable'], color: 'chain'),
            'hist' => $plot::of($rows)->col(['xmin' => 'x0', 'xmax' => 'x1', 'y' => 'count'], binSpacing: 1)->labs(x: $m['variable'], y: 'count'),
            'dens' => $plot::of($rows)->aes(x: 'x', y: 'density', color: $m['by_chain'] ? 'chain:N' : null)->line(linewidth: 1.5)->labs(x: $m['variable'], color: 'chain'),
            'intervals' => self::intervals($plot, $rows, $m),
            'rank_hist' => $plot::of($rows)->col(['xmin' => 'x0', 'xmax' => 'x1', 'y' => 'count'], binSpacing: 1)->hline(aes: ['yintercept' => 'mean(expected)'], linetype: 'dashed')
                ->facetGrid(rows: 'chain', header: ['labelAngle' => 0, 'labelAlign' => 'left', 'labelExpr' => "'chain ' + datum.value"])->labs(x: 'rank', y: 'count', chain: null),
            'scatter' => self::scatter($plot, $rows, $m),
            'acf' => $plot::of($rows)->aes(x: 'lag:O', y: 'acf')->col()->hline(0)
                ->facetGrid(rows: 'chain', header: ['labelAngle' => 0, 'labelAlign' => 'left', 'labelExpr' => "'chain ' + datum.value"])->labs(x: 'lag', y: 'autocorrelation', chain: null),
            'pareto_k' => $plot::of($rows)->aes(x: 'observation', y: 'k')->hline((float)$m['threshold'], linetype: 'dashed', color: 'gray')->hline(1, color: self::DIVERGENT)
                ->point(aes: ['color' => \Phiz\calc('datum.k > '.VegaLite::number((float)$m['threshold']), 'above threshold', 'nominal')])
                ->scaleColor(domain: [false, true], range: ['#4c78a8', self::DIVERGENT], guide: false)->labs(y: 'Pareto k'),
            default => throw new \InvalidArgumentException('Unknown figure kind: '.$figure->kind),
        };
        $perChain = in_array($figure->kind, ['rank_hist', 'acf'], true);
        $p = $p->size($width, $perChain ? (int)max(30, intdiv($height, max(1, (int)($m['chains'] ?? 1)))) : $height);
        if ($figure->kind === 'scatter' && $m['divergences']) {
            $n = count(array_filter($figure->data, fn($row) => $row['divergent']));
            return $p->title($figure->title, 'red: '.$n.' divergent transition'.($n === 1 ? '' : 's'));
        }
        return $p->title($figure->title);
    }
    private static function intervals(string $plot, array $rows, array $m): object
    {
        $p = $plot::of($rows)->aes(y: \Phiz\field('variable', 'nominal', sort: array_column($rows, 'variable')))
            ->rule(['x' => 'll', 'xend' => 'hh'], linewidth: 1.5)->rule(['x' => 'l', 'xend' => 'h'], linewidth: 5);
        if ($m['point_est'] !== 'none') { $p = $p->point(['x' => 'm'], size: 70, fill: 'white', stroke: 'black', opacity: 1); }
        return $p->labs(x: 'value', y: null);
    }
    private static function scatter(string $plot, array $rows, array $m): object
    {
        $p = $plot::of($rows)->aes(x: 'x', y: 'y')->point(filter: '!datum.divergent', size: 12, alpha: 0.4);
        if ($m['divergences']) { $p = $p->point(filter: 'datum.divergent', size: 30, color: self::DIVERGENT); }
        return $p->labs(x: $m['x_variable'], y: $m['y_variable']);
    }
}
