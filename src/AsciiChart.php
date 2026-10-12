<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Renders a Figure as printable ASCII lines with no trailing spaces. Marks are placed by rounding to character
 * cells, so the text shows shape; exact values remain in Figure::$data.
 */
final class AsciiChart
{
    /** One mark per chain, reused cyclically after eight chains. */
    public const MARKS = ['*', 'o', '+', 'x', '%', '@', '&', '$'];
    /** Scatter shading from the fewest to the most draws in a cell. */
    private const SHADES = ['.', ':', '-', '=', '+', '*', '#', '%', '@'];
    private const SIZES = ['trace' => [72, 15], 'hist' => [72, 15], 'dens' => [72, 15], 'intervals' => [72, 1], 'rank_hist' => [72, 4], 'scatter' => [72, 20], 'acf' => [72, 8], 'pareto_k' => [72, 15]];
    public static function render(Figure $figure, ?int $width = null, ?int $height = null): string
    {
        [$defaultWidth, $defaultHeight] = self::SIZES[$figure->kind] ?? throw new \InvalidArgumentException('Unknown figure kind: '.$figure->kind);
        $width ??= $defaultWidth; $height ??= $defaultHeight;
        $minimum = match ($figure->kind) { 'intervals' => 1, 'rank_hist', 'acf' => 2, default => 5 };
        if ($width < 30 || $height < $minimum) { throw new \InvalidArgumentException('ASCII figures need width >= 30 and height >= '.$minimum.' characters.'); }
        return match ($figure->kind) {
            'trace' => self::trace($figure, $width, $height), 'hist' => self::hist($figure, $width, $height), 'dens' => self::dens($figure, $width, $height),
            'intervals' => self::intervals($figure, $width), 'rank_hist' => self::rankHist($figure, $width, $height), 'scatter' => self::scatter($figure, $width, $height),
            'acf' => self::acf($figure, $width, $height), 'pareto_k' => self::paretoK($figure, $width, $height),
        };
    }
    private static function trace(Figure $f, int $width, int $height): string
    {
        $chains = self::group($f->data, 'chain', 'value'); $n = max(array_map('count', $chains)); $y = Plot::range(array_column($f->data, 'value')); $g = self::grid($width, $height);
        foreach (array_values($chains) as $c => $values) {
            $columns = [];
            foreach ($values as $i => $v) { $columns[$n > 1 ? self::col((float)$i, 0.0, (float)($n - 1), $width) : 0][] = self::row($v, $y, $height); }
            $previous = null;
            foreach ($columns as $col => $rows) {
                $span = $previous === null ? $rows : [...$rows, $previous];
                for ($r = min($span); $r <= max($span); ++$r) { self::put($g, $col, $r, self::mark($c)); }
                $previous = end($rows);
            }
        }
        return self::frame($f->title, $g, $y, [1.0, (float)$n], 'iteration', self::chainLegend(count($chains)));
    }
    private static function hist(Figure $f, int $width, int $height): string
    {
        $bins = count($f->data);
        if ($bins > $width) { throw new \InvalidArgumentException('Use at most one bin per character column, or a wider plot.'); }
        $y = [0.0, (float)max(1, max(array_column($f->data, 'count')))]; $g = self::grid($width, $height);
        foreach ($f->data as $b => $bin) {
            [$from, $to] = self::span($b, $bins, $width);
            for ($col = $from; $col <= $to; ++$col) { self::bar($g, $col, $bin['count'], $y, $height); }
        }
        return self::frame($f->title, $g, $y, [$f->data[0]['x0'], $f->data[$bins - 1]['x1']], $f->meta['variable'].' (y: count)');
    }
    private static function dens(Figure $f, int $width, int $height): string
    {
        $curves = [];
        foreach ($f->data as $row) { $curves[$row['chain'] ?? 0][] = $row; }
        $x = [min(array_column($f->data, 'x')), max(array_column($f->data, 'x'))]; $y = [0.0, max(array_column($f->data, 'density'))]; $g = self::grid($width, $height);
        foreach (array_values($curves) as $c => $points) {
            $lo = $points[0]['x']; $hi = end($points)['x']; $last = count($points) - 1; $rows = [];
            for ($col = 0; $col < $width; ++$col) {
                $at = $x[0] + $col * ($x[1] - $x[0]) / ($width - 1);
                if ($at < $lo - 1e-12 || $at > $hi + 1e-12) { continue; }
                $position = ($at - $lo) / ($hi - $lo) * $last; $i = min($last - 1, (int)floor($position)); $t = $position - $i;
                $rows[$col] = self::row($points[$i]['density'] * (1 - $t) + $points[$i + 1]['density'] * $t, $y, $height);
            }
            self::line($g, $rows, $f->meta['by_chain'] ? self::mark($c) : '*');
        }
        $title = $f->title.($f->meta['by_chain'] ? '' : ' (bandwidth '.self::num($f->meta['bandwidths'][0]).')');
        return self::frame($title, $g, $y, $x, $f->meta['variable'].' (y: density)', $f->meta['by_chain'] ? self::chainLegend(count($curves)) : []);
    }
    private static function intervals(Figure $f, int $width): string
    {
        $pad = max(array_map('strlen', array_column($f->data, 'variable'))); $plot = $width - $pad - 2;
        if ($plot < 20) { throw new \InvalidArgumentException('width is too small for these variable names.'); }
        $x = Plot::range(array_merge(array_column($f->data, 'll'), array_column($f->data, 'hh'))); $lines = [$f->title];
        foreach ($f->data as $row) {
            $g = self::grid($plot, 1); [$a, $b, $c, $d] = array_map(fn($key) => self::col($row[$key], $x[0], $x[1], $plot), ['ll', 'l', 'h', 'hh']);
            for ($col = $a; $col <= $d; ++$col) { self::put($g, $col, 0, $col >= $b && $col <= $c ? '=' : '-'); }
            if ($c > $b) { self::put($g, $b, 0, '['); self::put($g, $c, 0, ']'); }
            if ($row['m'] !== null) { self::put($g, self::col($row['m'], $x[0], $x[1], $plot), 0, 'o'); }
            $lines[] = str_pad($row['variable'], $pad, ' ', STR_PAD_LEFT).' |'.rtrim($g[0]);
        }
        $legend = str_repeat(' ', $pad + 2).'- outer, [=] inner'.($f->meta['point_est'] === 'none' ? '' : ', o = '.$f->meta['point_est']);
        return implode("\n", array_merge($lines, self::xAxis($pad, $plot, $x), [$legend]));
    }
    private static function rankHist(Figure $f, int $width, int $height): string
    {
        $plot = $width - 12; $bins = $f->meta['bins'];
        if ($bins > $plot) { throw new \InvalidArgumentException('Use at most one bin per character column, or a wider plot.'); }
        $y = [0.0, (float)max(1, max(array_column($f->data, 'count')))]; $lines = [$f->title];
        foreach (self::group($f->data, 'chain') as $chain => $rows) {
            $g = self::grid($plot, $height); $expected = self::row($rows[0]['expected'], $y, $height);
            for ($col = 0; $col < $plot; ++$col) { self::put($g, $col, $expected, '-'); }
            foreach ($rows as $b => $row) {
                [$from, $to] = self::span($b, $bins, $plot);
                for ($col = $from; $col <= $to; ++$col) { self::bar($g, $col, $row['count'], $y, $height); }
            }
            foreach ($g as $r => $line) { $lines[] = str_pad($r === 0 ? 'chain '.$chain : '', 9).' |'.rtrim($line); }
        }
        return implode("\n", array_merge($lines, self::xAxis(9, $plot, [1.0, (float)$f->meta['total']]), [str_repeat(' ', 11).'rank (y: count, max '.self::num($y[1]).'; - = expected)']));
    }
    private static function scatter(Figure $f, int $width, int $height): string
    {
        $x = Plot::range(array_column($f->data, 'x')); $y = Plot::range(array_column($f->data, 'y')); $cells = [];
        foreach ($f->data as $point) { $r = self::row($point['y'], $y, $height); $col = self::col($point['x'], $x[0], $x[1], $width); $cells[$r][$col] = ($cells[$r][$col] ?? 0) + 1; }
        $max = max(array_map('max', $cells)); $g = self::grid($width, $height);
        foreach ($cells as $r => $row) { foreach ($row as $col => $count) { self::put($g, $col, $r, self::SHADES[$max === 1 ? 0 : (int)floor(($count - 1) / ($max - 1) * (count(self::SHADES) - 1))]); } }
        $legend = ['shading: . (1 draw) to @ ('.$max.' draws per cell)'];
        if ($f->meta['divergences']) {
            $divergent = array_filter($f->data, fn($point) => $point['divergent']);
            foreach ($divergent as $point) { self::put($g, self::col($point['x'], $x[0], $x[1], $width), self::row($point['y'], $y, $height), 'X'); }
            $legend[] = 'X = divergent transition ('.count($divergent).')';
        }
        return self::frame($f->title, $g, $y, $x, $f->meta['x_variable'].' (y: '.$f->meta['y_variable'].')', $legend);
    }
    private static function acf(Figure $f, int $width, int $height): string
    {
        $plot = $width - 12; $lags = $f->meta['lags']; $values = array_filter(array_column($f->data, 'acf'), fn($r) => $r !== null);
        $y = [min(0.0, $values === [] ? 0.0 : min($values)), 1.0]; $zero = self::row(0.0, $y, $height); $lines = [$f->title];
        foreach (self::group($f->data, 'chain') as $chain => $rows) {
            $g = self::grid($plot, $height);
            for ($col = 0; $col < $plot; ++$col) { self::put($g, $col, $zero, '_'); }
            foreach ($rows as $row) {
                if ($row['acf'] === null) { continue; }
                [$from, $to] = self::span($row['lag'], $lags + 1, $plot); $top = self::row($row['acf'], $y, $height);
                for ($col = $from; $col <= $to; ++$col) { for ($k = min($top, $zero); $k <= max($top, $zero); ++$k) { self::put($g, $col, $k, '#'); } }
            }
            foreach ($g as $r => $line) { $lines[] = str_pad(match ($r) { 0 => 'chain '.$chain, $zero => '0', default => '' }, 9, ' ', STR_PAD_LEFT).' |'.rtrim($line); }
        }
        return implode("\n", array_merge($lines, self::xAxis(9, $plot, [0.0, (float)$lags]), [str_repeat(' ', 11).'lag (y: autocorrelation, top 1)']));
    }
    private static function paretoK(Figure $f, int $width, int $height): string
    {
        $k = array_column($f->data, 'k'); $threshold = $f->meta['threshold']; $n = count($k);
        $y = [min(0.0, min($k)), max(1.1, max($k))]; $g = self::grid($width, $height);
        for ($col = 0; $col < $width; ++$col) { self::put($g, $col, self::row($threshold, $y, $height), '.'); self::put($g, $col, self::row(1.0, $y, $height), '-'); }
        foreach ($k as $i => $value) { self::put($g, $n > 1 ? self::col((float)$i, 0.0, (float)($n - 1), $width) : 0, self::row($value, $y, $height), $value > $threshold ? 'X' : '*'); }
        $above = count(array_filter($k, fn($value) => $value > $threshold));
        return self::frame($f->title, $g, $y, [1.0, (float)$n], 'observation (y: Pareto k)', ['. = k threshold '.self::num($threshold).', - = k of 1, X = above threshold ('.$above.' of '.$n.')']);
    }
    /** Rows grouped by a key in first-seen order, optionally reduced to one column. */
    private static function group(array $rows, string $key, ?string $column = null): array
    {
        $out = [];
        foreach ($rows as $row) { $out[$row[$key]][] = $column === null ? $row : $row[$column]; }
        return $out;
    }
    private static function grid(int $width, int $height): array { return array_fill(0, $height, str_repeat(' ', $width)); }
    private static function put(array &$g, int $col, int $row, string $mark): void
    {
        if ($row >= 0 && $row < count($g) && $col >= 0 && $col < strlen($g[0])) { $g[$row][$col] = $mark; }
    }
    private static function mark(int $chain): string { return self::MARKS[$chain % count(self::MARKS)]; }
    private static function col(float $x, float $lo, float $hi, int $width): int { return (int)round(($x - $lo) / ($hi - $lo) * ($width - 1)); }
    private static function row(float $y, array $range, int $height): int { return $height - 1 - (int)round(($y - $range[0]) / ($range[1] - $range[0]) * ($height - 1)); }
    /** First and last column of bin $b, leaving a one-column gap between bars that are at least three columns wide. */
    private static function span(int $b, int $bins, int $width): array
    {
        $from = intdiv($b * $width, $bins); $to = intdiv(($b + 1) * $width, $bins) - 1;
        return [$from, $to - $from >= 2 ? $to - 1 : $to];
    }
    /** Fills a bar from the bottom row; a nonzero count too small for one row shows as '.'. */
    private static function bar(array &$g, int $col, int|float $count, array $y, int $height): void
    {
        $rows = (int)round($count / $y[1] * $height);
        if ($count > 0 && $rows === 0) { self::put($g, $col, $height - 1, '.'); }
        for ($r = 0; $r < $rows; ++$r) { self::put($g, $col, $height - 1 - $r, '#'); }
    }
    /** Draws a curve through one row per column, joining neighboring columns vertically. */
    private static function line(array &$g, array $rows, string $mark): void
    {
        foreach ($rows as $col => $row) {
            $previous = $rows[$col - 1] ?? $row;
            for ($r = min($row, $previous); $r <= max($row, $previous); ++$r) { self::put($g, $col, $r, $mark); }
        }
    }
    private static function num(float $x): string
    {
        if ($x == 0.0) { return '0'; }
        $s = sprintf(abs($x) >= 1e5 || abs($x) < 1e-3 ? '%.2e' : '%.4g', $x);
        return str_contains($s, 'e') ? $s : (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s);
    }
    private static function chainLegend(int $chains): array
    {
        return [implode('   ', array_map(fn($c) => 'chain '.($c + 1).' = '.self::mark($c), range(0, $chains - 1)))];
    }
    /** Title, labeled y ticks, an x axis, an axis caption, and legend lines around the plot grid. */
    private static function frame(string $title, array $g, array $y, array $x, string $caption, array $legend = []): string
    {
        $height = count($g); $width = strlen($g[0]); $labels = [];
        foreach (self::ticks($y[0], $y[1], max(2, intdiv($height, 3))) as $tick) { $labels[self::row($tick, $y, $height)] ??= self::num($tick); }
        $pad = max(array_map('strlen', $labels)); $lines = [$title];
        foreach ($g as $r => $row) { $lines[] = str_pad($labels[$r] ?? '', $pad, ' ', STR_PAD_LEFT).(isset($labels[$r]) ? ' +' : ' |').rtrim($row); }
        return implode("\n", array_merge($lines, self::xAxis($pad, $width, $x), [str_repeat(' ', $pad + 2).$caption], $legend));
    }
    /** Axis line with a '+' at each tick and the tick labels that fit without overlapping. */
    private static function xAxis(int $pad, int $width, array $x): array
    {
        $axis = str_repeat('-', $width); $labels = str_repeat(' ', $width); $free = 0;
        foreach (self::ticks($x[0], $x[1], max(2, intdiv($width, 14))) as $tick) {
            $col = self::col($tick, $x[0], $x[1], $width); $axis[$col] = '+'; $label = self::num($tick);
            $start = min(max(0, $col - intdiv(strlen($label), 2)), $width - strlen($label));
            if ($start >= $free) { $labels = substr_replace($labels, $label, $start, strlen($label)); $free = $start + strlen($label) + 1; }
        }
        return [str_repeat(' ', $pad).' +'.$axis, str_repeat(' ', $pad + 2).rtrim($labels)];
    }
    /** Tick values inside [lo, hi] spaced by 1, 2 or 5 times a power of ten, giving about $target intervals. */
    private static function ticks(float $lo, float $hi, int $target): array
    {
        $raw = ($hi - $lo) / $target; $magnitude = 10 ** floor(log10($raw)); $step = 10 * $magnitude;
        foreach ([1, 2, 5] as $m) { if ($m * $magnitude >= $raw * (1 - 1e-9)) { $step = $m * $magnitude; break; } }
        $ticks = [];
        for ($k = (int)ceil($lo / $step - 1e-9); $k * $step <= $hi + 1e-9 * $step; ++$k) { $ticks[] = $k * $step; }
        return $ticks;
    }
}
