<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Stan data built from data frames and named columns.
 *
 * A table is any object with toModelData() or toColumns(), such as a phibble Phibble\Frame; phibble is not a dependency.
 * Conversion rules (fromTable(), and every model method's data argument):
 * - int and float columns become Stan arrays unchanged; booleans become 0/1; list cells (arrays) stay nested arrays.
 * - Factor columns become 1-based integer indices, in level order, and add J_<name> (the number of levels). Levels come
 *   from a toModelData() factor descriptor ['levels' => list<string>, 'codes' => list<?int>, 'ordered' => bool], whose
 *   codes are 0-based as phibble's Frame::toModelData() returns them (a 'base' => 1 entry marks 1-based codes), or
 *   from the table's levels(name) when toColumns() is used and types() reports a factor. String columns are
 *   treated as factors with sorted unique levels (strcmp order, as phibble's and R's factor() defaults). The labels
 *   are kept in $levels; they are not written to the Stan JSON, which holds numbers only.
 * - N, the row count, is added unless the table has a column named N. J_<name> is added unless that name is taken.
 * - Missing values (null), dates, and other objects are rejected, since Stan has no missing values.
 *
 * fromColumns() and fromFormula() build the usual regression data block: N, K, X (N x K rows), y, and per grouping
 * factor g the indices g_id (1..J_g) and J_g, plus J when there is exactly one grouping factor.
 */
final class StanData
{
    /**
     * @param array<string, mixed> $data Stan data, ready for write_stan_json()
     * @param array<string, list<string>> $levels Labels of each factor or group, by data variable; index i is level i + 1
     * @param list<string> $columns Names of the columns of X, when the data has a design matrix
     * @param array $meta Extra bookkeeping, such as the source rows kept by stats_model_matrix()
     */
    public function __construct(public readonly array $data, public readonly array $levels = [], public readonly array $columns = [], public readonly array $meta = []) {}
    public function toArray(): array { return $this->data; }
    /** A copy with additional or replaced data variables, e.g. ->with(['prior_scale' => 2.5]). */
    public function with(array $data): self { return new self(array_merge($this->data, $data), $this->levels, $this->columns, $this->meta); }
    /** True for objects that can be converted to Stan data: a StanData, or a table with toModelData() or toColumns(). */
    public static function isTable(mixed $value): bool { return is_object($value) && (method_exists($value, 'toModelData') || method_exists($value, 'toColumns')); }
    /** Data argument normalization: null and arrays pass through; StanData and tables become Stan data arrays. */
    public static function normalize(mixed $data): mixed
    {
        if ($data instanceof self) { return $data->data; }
        if (self::isTable($data)) { return self::fromTable($data)->data; }
        return $data;
    }
    /** Converts a table to Stan data; see the class description for the conventions. */
    public static function fromTable(object $table, bool $add_n = true): self
    {
        $data = []; $levels = []; $n = null;
        foreach (self::tableColumns($table) as $name => $column) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) { throw new \InvalidArgumentException("Column '".$name."' is not a valid Stan variable name; rename it first."); }
            $values = $column['values'];
            if ($n !== null && count($values) !== $n) { throw new \InvalidArgumentException('Table columns must have equal lengths.'); }
            $n = count($values);
            if ($column['levels'] !== null) { $levels[$name] = $column['levels']; $data[$name] = $values; }
            else { $data[$name] = array_map(fn($value, $row) => self::cell($value, $name, $row), $values, array_keys($values)); }
        }
        foreach ($levels as $name => $labels) { $data['J_'.$name] ??= count($labels); }
        if ($add_n && !array_key_exists('N', $data)) { $data = ['N' => $n ?? 0] + $data; }
        return new self($data, $levels);
    }
    /**
     * Regression data from named columns or a table: N, K, X, y, and for each grouping column g: g_id (1..J_g) and J_g,
     * plus J for a single grouping column. Predictors must be numeric (use fromFormula() for factor predictors and
     * interactions). $intercept prepends a column of ones named '(Intercept)'; it is off by default because Stan
     * programs usually give the intercept its own parameter.
     *
     * @param array<string, list<mixed>>|object $columns
     * @param list<string>|string $x predictor column names, in X column order
     * @param list<string>|string $groups grouping column names
     */
    public static function fromColumns(array|object $columns, string $y, array|string $x = [], array|string $groups = [], bool $intercept = false): self
    {
        $table = is_object($columns) ? self::tableColumns($columns) : array_map(fn($values) => ['values' => self::listOf($values), 'levels' => null], $columns);
        $get = fn(string $name) => $table[$name] ?? throw new \InvalidArgumentException('Unknown column: '.$name);
        $yValues = $get($y); $n = count($yValues['values']);
        if ($yValues['levels'] !== null) { throw new \InvalidArgumentException("Outcome '".$y."' must be numeric."); }
        $names = (array)$x; $predictors = [];
        foreach ($names as $name) {
            $column = $get($name);
            if ($column['levels'] !== null || count($column['values']) !== $n) { throw new \InvalidArgumentException("Predictor '".$name."' must be a numeric column with one value per row; use fromFormula() for factor predictors."); }
            $predictors[] = array_map(fn($value, $row) => (float)self::cell($value, $name, $row, true), $column['values'], array_keys($column['values']));
        }
        $rows = [];
        for ($i = 0; $i < $n; ++$i) { $rows[] = $intercept ? [1.0] : []; foreach ($predictors as $values) { $rows[$i][] = $values[$i]; } }
        $groupData = [];
        foreach ((array)$groups as $g) {
            $column = $get($g);
            if (count($column['values']) !== $n) { throw new \InvalidArgumentException("Group '".$g."' needs one value per row."); }
            $groupData[$g] = $column['levels'] === null ? self::factor($column['values'], $g) : ['index' => $column['values'], 'levels' => $column['levels']];
        }
        return self::assemble($n, $rows, $intercept ? ['(Intercept)', ...$names] : $names, array_map(fn($value, $row) => self::cell($value, $y, $row, true), $yValues['values'], array_keys($yValues['values'])), $groupData);
    }
    /**
     * Regression data from an R-style formula, e.g. 'y ~ x1 + x2 + (1 | g)', using stats_model_matrix() from the
     * php_stats extension: N, K, X, y, and the group indices of the random-effect terms, as fromColumns() names them.
     * $drop_intercept removes the model matrix's '(Intercept)' column for programs with a separate intercept parameter.
     *
     * @param array<string, list<mixed>>|object $data columns, or a table: its toModelData() is passed unchanged (the
     *     input stats_model_matrix() expects); a table with only toColumns() is passed with factor labels
     */
    public static function fromFormula(string $formula, array|object $data, bool $drop_intercept = false): self
    {
        if (!function_exists('stats_model_matrix')) { throw new \RuntimeException('StanData::fromFormula() needs stats_model_matrix() from the php_stats extension (https://github.com/erikwestlund/php-stats), which is not loaded or predates the function. Use StanData::fromColumns() without it.'); }
        if (is_object($data)) { $data = method_exists($data, 'toModelData') ? $data->toModelData() : array_map(fn($column) => $column['levels'] === null ? $column['values'] : array_map(fn($i) => $column['levels'][$i - 1], $column['values']), self::tableColumns($data)); }
        return self::fromModelMatrix(\stats_model_matrix($formula, $data), $drop_intercept);
    }
    /**
     * Adapts a stats_model_matrix() result: ['y' => list<float>, 'x' => rows, 'columns' => names, 'intercept' => bool,
     * 'random' => list of ['group' => name, 'levels' => labels, 'index' => per-row group indices], plus row bookkeeping
     * such as 'rows' (kept in $meta). Indices may be 0- or 1-based; they become 1-based. This is the only code that
     * depends on the function's result shape.
     */
    public static function fromModelMatrix(array $mm, bool $drop_intercept = false): self
    {
        foreach (['y', 'x', 'columns'] as $key) { if (!array_key_exists($key, $mm)) { throw new \UnexpectedValueException("stats_model_matrix() result lacks '".$key."'."); } }
        $columns = array_values(array_map('strval', $mm['columns'])); $rows = array_map(fn($row) => array_map('floatval', array_values($row)), array_values($mm['x']));
        $drop = $drop_intercept && ($mm['intercept'] ?? in_array('(Intercept)', $columns, true)) ? array_search('(Intercept)', $columns, true) : false;
        if ($drop !== false) { unset($columns[$drop]); $columns = array_values($columns); $rows = array_map(function ($row) use ($drop) { unset($row[$drop]); return array_values($row); }, $rows); }
        $groups = [];
        foreach ($mm['random'] ?? [] as $key => $term) {
            $name = (string)($term['group'] ?? $term['name'] ?? (is_string($key) ? $key : throw new \UnexpectedValueException('A random-effect term has no group name.')));
            $index = array_values($term['index'] ?? $term['indices'] ?? $term['codes'] ?? throw new \UnexpectedValueException("Random-effect term '".$name."' has no group indices."));
            $levels = array_values(array_map('strval', $term['levels'] ?? $term['labels'] ?? []));
            $base = $index !== [] && (int)min($index) === 0 ? 1 : 0; $index = array_map(fn($i) => (int)$i + $base, $index);
            if ($levels === []) { $levels = $index === [] ? [] : array_map('strval', range(1, max($index))); }
            if ($index !== [] && (min($index) < 1 || max($index) > count($levels))) { throw new \UnexpectedValueException("Random-effect term '".$name."' has indices outside its levels."); }
            $groups[$name] = ['index' => $index, 'levels' => $levels];
        }
        $y = array_values($mm['y']);
        $meta = array_diff_key($mm, array_flip(['y', 'x', 'columns', 'random']));
        $data = self::assemble(count($y), $rows, $columns, $y, $groups);
        return new self($data->data, $data->levels, $data->columns, $meta);
    }
    private static function assemble(int $n, array $rows, array $columns, array $y, array $groups): self
    {
        if (count($rows) !== $n) { throw new \InvalidArgumentException('The design matrix needs one row per outcome.'); }
        $data = ['N' => $n, 'K' => count($columns), 'X' => $rows, 'y' => $y]; $levels = [];
        foreach ($groups as $g => $group) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $g)) { throw new \InvalidArgumentException("Group '".$g."' is not usable in a Stan variable name."); }
            if (count($group['index']) !== $n) { throw new \InvalidArgumentException("Group '".$g."' needs one index per row."); }
            $data[$g.'_id'] = $group['index']; $data['J_'.$g] = count($group['levels']); $levels[$g.'_id'] = $group['levels'];
        }
        if (count($groups) === 1) { $data['J'] = $data['J_'.array_key_first($groups)]; }
        return new self($data, $levels, $columns);
    }
    /** @return array<string, array{values: list<mixed>, levels: ?list<string>}> factors hold 1-based indices */
    private static function tableColumns(object $table): array
    {
        if (!self::isTable($table)) { throw new \InvalidArgumentException('Expected a table with toModelData() or toColumns().'); }
        $model = method_exists($table, 'toModelData');
        $raw = $model ? $table->toModelData() : $table->toColumns();
        if (!is_array($raw)) { throw new \UnexpectedValueException(($model ? 'toModelData()' : 'toColumns()').' must return an array of columns.'); }
        $types = !$model && method_exists($table, 'types') ? $table->types() : [];
        $out = [];
        foreach ($raw as $name => $values) {
            $name = (string)$name;
            if (is_array($values) && array_key_exists('levels', $values) && array_key_exists('codes', $values)) {
                $labels = array_values(array_map('strval', $values['levels'])); $base = (int)($values['base'] ?? 0);
                if ($base !== 0 && $base !== 1) { throw new \UnexpectedValueException("Factor '".$name."' has an unsupported code base."); }
                $index = [];
                foreach (array_values($values['codes']) as $row => $code) {
                    if ($code === null) { throw new \InvalidArgumentException("Factor '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
                    $code = (int)$code + 1 - $base;
                    if ($code < 1 || $code > count($labels)) { throw new \UnexpectedValueException("Factor '".$name."' has a code outside its levels at row ".($row + 1).'.'); }
                    $index[] = $code;
                }
                $out[$name] = ['values' => $index, 'levels' => $labels];
                continue;
            }
            $values = self::listOf($values);
            $type = $types[$name] ?? null; $type = $type instanceof \UnitEnum ? ($type instanceof \BackedEnum ? $type->value : $type->name) : $type;
            if (is_string($type) && strtolower($type) === 'factor' && method_exists($table, 'levels')) {
                $labels = array_values(array_map('strval', $table->levels($name))); $positions = array_flip($labels); $index = [];
                foreach ($values as $row => $label) { $index[] = $label === null ? throw new \InvalidArgumentException("Factor '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.') : ($positions[(string)$label] ?? throw new \UnexpectedValueException("Factor '".$name."' has an unknown level at row ".($row + 1).'.')) + 1; }
                $out[$name] = ['values' => $index, 'levels' => $labels];
            } elseif ($values !== [] && array_filter($values, 'is_string') !== []) {
                $factor = self::factor($values, $name); $out[$name] = ['values' => $factor['index'], 'levels' => $factor['levels']];
            } else { $out[$name] = ['values' => $values, 'levels' => null]; }
        }
        return $out;
    }
    /** 1-based indices over sorted unique labels: numbers numerically, otherwise in strcmp order. */
    private static function factor(array $values, string $name): array
    {
        foreach ($values as $row => $value) {
            if ($value === null) { throw new \InvalidArgumentException("Column '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) { throw new \InvalidArgumentException("Column '".$name."' has a value that cannot be a factor level at row ".($row + 1).'.'); }
        }
        $label = fn($v) => is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (string)$v;
        $unique = []; foreach ($values as $value) { $unique[$label($value)] = $value; }
        $unique = array_values($unique);
        $numeric = array_filter($unique, fn($v) => !is_int($v) && !is_float($v)) === [];
        $numeric ? sort($unique) : usort($unique, fn($a, $b) => strcmp($label($a), $label($b)));
        $levels = array_map($label, $unique); $positions = array_flip($levels);
        return ['index' => array_map(fn($v) => $positions[$label($v)] + 1, $values), 'levels' => $levels];
    }
    private static function cell(mixed $value, string $name, int $row, bool $scalar = false): mixed
    {
        if ($value === null) { throw new \InvalidArgumentException("Column '".$name."' is missing at row ".($row + 1).'; Stan data cannot hold missing values.'); }
        if (is_bool($value)) { return (int)$value; }
        if ((is_int($value) || is_float($value)) || (!$scalar && is_array($value))) { return $value; }
        throw new \InvalidArgumentException("Column '".$name."' has a non-numeric value at row ".($row + 1).'.');
    }
    private static function listOf(mixed $values): array
    {
        if ($values instanceof \Traversable) { $values = iterator_to_array($values, false); }
        if (!is_array($values)) { throw new \InvalidArgumentException('Each column must be a list of values.'); }
        return array_values($values);
    }
}
