<?php
declare(strict_types=1);
namespace CmdStan;
/** Strict reader for CmdStan CSV, including adaptation comments and special float values. */
final class Csv
{
    public static function variableName(string $name): string
    {
        if (preg_match('/^([^.]+)((?:\.\d+)+)$/D', $name, $m)) { return $m[1].'['.str_replace('.', ',', substr($m[2], 1)).']'; }
        return $name;
    }
    public static function read(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) { throw new \InvalidArgumentException('Cannot read CSV: '.$file); }
        $metadata = []; $header = null; $rows = []; $comments = []; $warmupRows = null; $stepSize = null; $metric = []; $readingMetric = false; $lineNumber = 0; $lp = null; $gradients = []; $time = ['warmup' => 0.0, 'sampling' => 0.0, 'total' => 0.0];
        try {
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber; $line = trim($line);
                if ($line === '') { continue; }
                if (str_starts_with($line, '#')) {
                    $comment = trim(substr($line, 1)); $comments[] = $comment;
                    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $comment, $m)) {
                        $value = trim(preg_replace('/\s+\(Default\)$/', '', $m[2]));
                        $metadata[$m[1]] = match (strtolower($value)) {
                            'true' => true,
                            'false' => false,
                            default => is_numeric($value) ? (str_contains($value, '.') || stripos($value, 'e') !== false ? (float)$value : (int)$value) : $value,
                        };
                    }
                    if (str_starts_with($comment, 'Adaptation terminated')) { $warmupRows = count($rows); $readingMetric = false; }
                    if (preg_match('/([0-9.eE+-]+) seconds \((Warm-up|Sampling|Total)\)/', $comment, $m)) { $time[match ($m[2]) { 'Warm-up' => 'warmup', 'Sampling' => 'sampling', default => 'total' }] = (float)$m[1]; }
                    if (preg_match('/^Log probability\s*=\s*(\S+)/', $comment, $m)) { $lp = (float)$m[1]; }
                    elseif ($lp !== null && preg_match('/^\s*-?\d+(\s+\S+){4}\s*$/D', $comment) && count($g = preg_split('/\s+/', trim($comment))) === 5 && count(array_filter($g, 'is_numeric')) === 5) { $gradients[] = array_combine(['param_idx', 'value', 'model', 'finite_diff', 'error'], array_map('floatval', $g)); }
                    if (preg_match('/^Step size = (.*)$/', $comment, $m)) { $stepSize = (float)$m[1]; }
                    elseif (str_contains($comment, 'inverse mass matrix:')) { $readingMetric = true; }
                    elseif ($readingMetric && preg_match('/^[-+0-9.eE, ]+$/D', $comment)) { $metric[] = array_map('floatval', explode(',', $comment)); }
                    elseif ($readingMetric) { $readingMetric = false; }
                    continue;
                }
                $fields = str_getcsv($line, ',', '"', '');
                if ($header === null) {
                    if ($fields === [] || count(array_unique($fields)) !== count($fields)) { throw new \RuntimeException('Invalid or duplicate CSV columns.'); }
                    foreach ($fields as $field) { if ($field === '' || is_numeric($field)) { throw new \RuntimeException('Invalid CSV header.'); } }
                    $header = array_map(self::variableName(...), $fields); continue;
                }
                if (count($fields) !== count($header)) { throw new \RuntimeException('CSV row width mismatch at '.$file.':'.$lineNumber); }
                $values = [];
                foreach ($fields as $value) {
                    $value = trim($value);
                    $values[] = match (strtolower($value)) {
                        'nan', '+nan', '-nan' => NAN,
                        'inf', '+inf', 'infinity', '+infinity' => INF,
                        '-inf', '-infinity' => -INF,
                        default => is_numeric($value) ? (float)$value : throw new \RuntimeException('Invalid CSV number at '.$file.':'.$lineNumber),
                    };
                }
                $rows[] = array_combine($header, $values);
            }
            if (!feof($handle)) { throw new \RuntimeException('CSV read failed.'); }
        } finally { fclose($handle); }
        if ($header === null && ($metadata['method'] ?? null) !== 'diagnose') { throw new \RuntimeException('CSV has no header: '.$file); }
        $header ??= [];
        $method = $metadata['method'] ?? 'sample';
        $warmupCount = 0;
        if ($method === 'sample' && ($metadata['save_warmup'] ?? 0)) {
            $warmupCount = $warmupRows ?? (int)ceil(($metadata['num_warmup'] ?? 0) / max(1, $metadata['thin'] ?? 1));
        }
        $warmup = array_slice($rows, 0, $warmupCount); $draws = array_slice($rows, $warmupCount);
        $estimate = null;
        if ($method === 'variational' && $draws !== []) { $estimate = array_shift($draws); }
        $columns = $header;
        if (in_array($method, ['variational', 'laplace'], true) && in_array('log_p__', $header, true)) {
            // CmdStanR exposes log_p__ as lp__ and log_g__/log_q__ as lp_approx__; ADVI's original lp__ is a placeholder.
            $keep = array_values(array_filter($header, fn($name) => $name !== 'lp__'));
            $header = array_map(fn($name) => ['log_p__' => 'lp__', 'log_g__' => 'lp_approx__', 'log_q__' => 'lp_approx__'][$name] ?? $name, $keep);
            $map = fn(?array $row) => $row === null ? null : array_combine($header, array_map(fn($name) => $row[$name], $keep));
            $draws = array_map($map, $draws); $estimate = $map($estimate);
        }
        if ($method === 'optimize' && $draws !== []) { $estimate = end($draws); }
        $metadata += self::cmdstanrMetadata($metadata, $columns, $stepSize, $lp, $time);
        return ['variables' => $header, 'draws' => $draws, 'warmup' => $warmup, 'metadata' => $metadata, 'comments' => $comments, 'step_size' => $stepSize, 'inv_metric' => count($metric) === 1 ? $metric[0] : $metric, 'estimate' => $estimate, 'gradients' => $gradients];
    }
    /** Keys added by CmdStanR's read_csv_metadata()/read_cmdstan_csv(); CmdStan's raw argument keys are retained alongside them. */
    private static function cmdstanrMetadata(array $raw, array $columns, ?float $stepSize, ?float $lp, array $time): array
    {
        $method = $raw['method'] ?? 'sample'; $out = [];
        foreach (['model' => 'model_name', 'engaged' => 'adapt_engaged', 'delta' => 'adapt_delta', 'max_depth' => 'max_treedepth', 'stepsize' => 'step_size', 'num_warmup' => 'iter_warmup', 'num_samples' => 'iter_sampling'] as $from => $to) {
            if (array_key_exists($from, $raw)) { $out[$to] = $raw[$from]; }
        }
        if (array_key_exists('num_threads', $raw)) { $out[in_array($method, ['variational', 'optimize', 'laplace'], true) ? 'threads' : 'threads_per_chain'] = $raw['num_threads']; }
        if ($stepSize !== null) { $out['step_size_adaptation'] = $stepSize; }
        if ($lp !== null) { $out['lp'] = $lp; }
        if (($raw['algorithm'] ?? null) === 'fixed_param') { $diagnostics = []; $variables = array_values(array_filter($columns, fn($v) => !str_ends_with($v, '__'))); }
        else {
            $diagnostics = array_values(array_filter($columns, fn($v) => str_ends_with($v, '__') && !in_array($v, ['lp__', 'log_p__', 'log_g__', 'log_q__'], true)));
            $variables = array_values(array_diff($columns, $diagnostics));
        }
        if ($method === 'variational') { $variables = array_values(array_filter($variables, fn($v) => $v !== 'lp__')); }
        $variables = array_map(fn($v) => ['log_p__' => 'lp__', 'log_g__' => 'lp_approx__', 'log_q__' => 'lp_approx__'][$v] ?? $v, $variables);
        if ($method === 'pathfinder') { $variables = array_values(array_unique(array_merge($diagnostics, $variables))); }
        $sizes = [];
        foreach ($variables as $v) {
            $name = preg_replace('/\[.*\]$/', '', $v);
            $sizes[$name] = preg_match('/\[(.*)\]$/', $v, $m) ? array_map('intval', explode(',', $m[1])) : [1];
        }
        $out += ['sampler_diagnostics' => $diagnostics, 'variables' => $variables, 'stan_variables' => array_keys($sizes), 'stan_variable_sizes' => $sizes, 'model_params' => $variables];
        if ($method === 'sample') { $out['time'] = ['chain_id' => $raw['id'] ?? 1] + $time; }
        return $out;
    }
}
