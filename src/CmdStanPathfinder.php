<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanPathfinder extends CmdStanFit
{
    public function lp_approx(): array { return $this->approximateLp('lp_approx__'); }
    /** Per-path draws CmdStan writes as <output>_path_<n>.csv when save_single_paths is true, in path order. */
    public function single_path_files(): array { return $this->context['single_path_files'] ??= $this->pathFiles('csv'); }
    /** Per-path L-BFGS/ELBO diagnostics CmdStan writes as <output>_path_<n>.json when save_single_paths is true. */
    public function single_path_json_files(): array { return $this->context['single_path_json_files'] ??= $this->pathFiles('json'); }
    /** One single-path CmdStanPathfinder fit per path CSV (CmdStanR does not expose these). */
    public function single_path_fits(): array
    {
        return array_map(fn($file) => new self([$file], 'pathfinder', context: array_intersect_key($this->context, array_flip(['code', 'model_name', 'stan_file', 'stanc_options', 'data_file']))),$this->single_path_files());
    }
    /** Decoded per-path JSON diagnostics, one array per path. */
    public function single_path_diagnostics(): array
    {
        return array_map(fn($file) => json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $this->single_path_json_files());
    }
    protected function pinFiles(): void { $this->single_path_files(); $this->single_path_json_files(); }
    /** With num_paths = 1 CmdStan writes no _path_ files: the output CSV is the single path and its diagnostics are <output>.json. */
    private function pathFiles(string $extension): array
    {
        $stem = basename($this->files[0], '.csv'); $dir = dirname($this->files[0]); $found = [];
        $meta = $this->chain_metadata()[0];
        if (($meta['num_paths'] ?? null) === 1 && ($meta['save_single_paths'] ?? false) === true) { return array_values(array_filter([$dir.'/'.$stem.'.'.$extension], 'is_file')); }
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/^'.preg_quote($stem, '/').'_path_(\d+)\.'.$extension.'$/D', $entry, $m)) { $found[(int)$m[1]] = $dir.'/'.$entry; }
        }
        ksort($found);
        return array_values($found);
    }
}
