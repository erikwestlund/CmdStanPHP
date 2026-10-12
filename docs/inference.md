---
layout: page
title: Inference guide
---

## MCMC

```php
$model = CmdStan\cmdstan_model('examples/bernoulli.stan');
$data = ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]];
$fit = $model->sample(
    data: $data, seed: 123, chains: 4, parallel_chains: 2,
    iter_warmup: 500, iter_sampling: 1000,
    adapt_delta: 0.9, max_treedepth: 12,
    output_dir: 'output', show_messages: false,
);
print_r($fit->summary('theta'));
print_r($fit->diagnostic_summary());
print_r($fit->diagnostic_warnings());
```

`chains` controls independent processes; `parallel_chains` bounds their concurrent execution. IDs are unique positive integers. A scalar seed is shared across chains and Stan uses IDs to create separate random streams; a seed list provides one seed per chain. For a threaded model, `threads_per_chain` sets both `num_threads` and `STAN_NUM_THREADS`. Budget CPU use as parallel chains multiplied by threads per chain.

`init` accepts a nonnegative numeric radius, a file, an associative parameter array, a list of files/arrays (one per chain), a callable accepting the chain ID, or a fit object. For example `init: fn($id) => ['theta' => $id / 5]`. Precomputed inverse metrics can be supplied with `inv_metric`, or `metric_file` for one file per chain. Use `metric: 'dense_e'` for matrix metrics.

Diagnostics include divergent transitions, transitions reaching maximum tree depth, and E-BFMI. Checks populate `diagnostic_warnings()` rather than emitting PHP warnings. `diagnostics: []` or `null` disables automatic checks. `cmdstan_diagnose()` runs CmdStan's diagnostic utility. Review diagnostics before relying on an estimate.

## Initialization from fits

```php
$pf = $model->pathfinder(data: $data, seed: 123);
$fit = $model->sample(data: $data, init: $pf, seed: 123, chains: 4);
```

A fit used as `init` supplies one draw per chain, as CmdStanR's `process_init()` does. MCMC draws are resampled without replacement. An optimization fit repeats its estimate for every chain. ADVI, Laplace, and Pathfinder draws are resampled with Pareto-smoothed importance weights computed from `lp__ - lp_approx__`; too few distinct draws throws. Flat columns such as `beta[2,1]` become nested arrays matched to the model's parameter declarations. Variables other than parameters are dropped, and draws containing NaN or infinite values throw. The seed controls resampling with PHP's Mt19937 engine, so the selected draws differ from CmdStanR's for the same seed.

## Optimization and Laplace

```php
$mode = $model->optimize(data: $data, jacobian: true, seed: 123);
print_r($mode->mle());
$fit = $model->laplace(data: $data, mode: $mode, draws: 1000, seed: 123);
```

Optimization supports L-BFGS, BFGS, and Newton. `jacobian: false` is the optimization default; Laplace defaults to `true`. A fit supplied as a Laplace mode must agree on Jacobian adjustment. If no mode is supplied, the library optimizes first using the same data and Jacobian setting; `opt_args` supplies additional optimizer options. A mode can also be a parameter JSON file, optimizer CSV file, or associative parameter array.

## ADVI and Pathfinder

```php
$vb = $model->variational(data: $data, algorithm: 'meanfield', draws: 1000, seed: 123);
$pf = $model->pathfinder(data: $data, num_paths: 4, single_path_draws: 500, draws: 1000, seed: 123);
```

ADVI supports `meanfield` and `fullrank`. Its first CSV row is the variational mean and is excluded from posterior draws; retrieve it with `variational_mean()`. Pathfinder's `single_path_draws` maps to `num_draws`, and `draws` maps to `num_psis_draws`. With `save_single_paths: true`, `single_path_fits()` returns one `CmdStanPathfinder` per path and `single_path_diagnostics()` decodes each path's JSON output. These approximations have different convergence diagnostics than MCMC; summaries do not report MCMC ESS or R-hat for them.

## Generated quantities and fixed parameters

```php
$predictionModel = CmdStan\cmdstan_model('examples/generated.stan');
$gq = $predictionModel->generate_quantities($fit, data: $data, seed: 123, parallel_chains: 2);
$predictions = $gq->draws('prediction', 'draws_matrix');
```

Pass a fit object, a CSV filename, or a list of CSV filenames. Draw matrices can be exported first with `draws_to_csv()`. Parameter declarations must match the fitted model. Use `sample(fixed_param: true, iter_sampling: 100, ...)` to simulate generated quantities at fixed parameters; this selects one chain and zero warmup.

## Diagnostics and log density

```php
$normal = CmdStan\cmdstan_model('examples/normal.stan');
$gradientCheck = $normal->diagnose(init: 0);
echo $gradientCheck->gradients();
$logDensity = $normal->log_prob(unconstrained_params: ['params_r' => [[0.0], [1.0]]]);
print_r($logDensity->draws(format: 'draws_matrix'));
```

`log_prob()` takes exactly one of `unconstrained_params` or `constrained_params`, using CmdStan's input JSON format. This runs the executable's batch log-density method. The in-process methods below evaluate one point at a time without starting a process.

## Model methods and Stan functions

```php
$fit->init_model_methods(seed: 1);
$u = $fit->unconstrain_variables(['theta' => 0.3]);
$lp = $fit->log_prob($u);
$gradient = $fit->grad_log_prob($u, jacobian: false)['gradient'];
$hessian = $fit->hessian($u)['hessian'];
$values = $fit->constrain_variables($u);
$unconstrained = $fit->unconstrain_draws(format: 'draws_matrix');
```

Model methods compile a shared library from the model's generated C++ with the selected CmdStan's compiler settings and load it with PHP FFI. The first build takes a few seconds; later calls reuse the library cached beside the Stan source. The library is bound to the fit's data and to a seed for the generated-quantities RNG. Log densities drop constant terms, and `jacobian` defaults to true, as in CmdStanR. An imported CSV fit needs the model: `$fit->init_model_methods(model: $model)`.

Unconstrained values are flat lists in Stan's order, and `unconstrain_draws()` labels them with Stan's unconstrained names. Constrained values are keyed by variable name, with arrays and vectors as lists, matrices as lists of rows, and complex numbers as `[re, im]` pairs. `variable_skeleton()` reports each variable's dimensions.

```php
$functions = $model->expose_functions();
$functions->my_function(1.5, [1.0, 2.0]);
$functions->call('normal_draw_rng', 0.0, 1.0, seed: 42);
```

`expose_functions()` compiles the model's `functions` block. Arguments may be positional or named by their Stan names. Supported types are real, int, complex, vectors, matrices, arrays of these, and tuples. Functions ending in `_rng` take an optional `seed`. Density functions ending in `_lpdf` or `_lpmf` return the full density. Functions ending in `_lp` and overloaded functions cannot be called. `functions()` on the returned object lists each signature and whether it is supported.

FFI is enabled for the CLI by default. Under PHP-FPM or another web SAPI, set `ffi.enable=true` or preload the library.

## LOO and WAIC

```php
$loo = $fit->loo('log_lik');
echo $loo['elpd_loo'], ' ± ', $loo['se_elpd_loo'], "\n";
print_r($loo['diagnostics']['pareto_k']);
$waic = $fit->waic('log_lik');
```

`loo()` computes PSIS-LOO from a pointwise log-likelihood variable in generated quantities. It follows the loo package as called by CmdStanR, including relative efficiencies with `r_eff: true`. Results contain `estimates`, `pointwise` rows, Pareto k diagnostics, and `k_threshold`. Inspect observations whose Pareto k exceeds the threshold. Moment matching is not implemented.

## Results and persistence

```php
$rows = $fit->draws('theta', 'draws_df');
$csvFiles = $fit->save_output_files('saved');
$fit->save_object('saved/fit.bin');
$imported = CmdStan\read_cmdstan_csv($csvFiles);
```

`draws_array` is `[iteration][chain][variable]`, with zero-based PHP indices and associative variable names. `draws_matrix` concatenates chain rows. `draws_df` adds one-based `.chain`, `.iteration`, and `.draw` metadata. `draws_list` gives a variable-to-values map per chain. `draws_rvars` maps each variable's base name to `dims`, `nchains`, `ndraws`, and `draws`, where `draws` is nested by the variable's dimensions and each leaf lists all draws chain by chain. Without a format, MCMC and generated quantities fits return `draws_array` and other fits return `draws_matrix`, unless the `cmdstanr_draws_format` option is set. Array format requires equal chain lengths; other formats permit unequal lengths. Selecting a base name such as `beta` includes all `beta[i]` elements. Warmup is excluded unless `inc_warmup: true`.

`summary()` returns mean, median, sample SD, scaled MAD, requested quantiles (default q5/q95), and, for MCMC, rank-normalized R-hat, bulk ESS, and MCSE from CmdStan's `stansummary`, plus tail ESS using posterior-compatible inclusive quantile indicators and Geyer's truncation. Non-MCMC or fewer than four sampling draws return `NAN` for convergence statistics.

```php
$fit->summary('theta', measures: ['mean', 'sd', 'rhat', 'ess_bulk', 'quantile2']);
$fit->summary(null, [0.1, 0.9], ['median', 'range' => fn(array $x) => max($x) - min($x)]);
```

The `measures` argument follows posterior's `summarise_draws()`. Named measures include `mean`, `median`, `sd`, `var`, `mad`, `quantile2`, `rhat`, `ess_bulk`, `ess_tail`, `mcse_mean`, `mcse_sd`, `mcse_quantile`, and the groups `default_summary_measures`, `default_convergence_measures`, and `default_mcse_measures`. A callable needs a string key. It receives all draws as one list, and also the list of chains if it declares a second parameter. These measures are computed in PHP and do not need CmdStan. Nonfinite values can also produce `NAN`; use appropriate JSON handling when exposing summaries over HTTP.

Runs create private temporary output directories unless `output_dir` is supplied. Output directories persist after the fit object is collected; delete them explicitly when no longer needed. Saving output follows CmdStanR's file naming and updates the fit's paths. Files created by the run are moved, imported CSVs and user-supplied data files are copied, and existing destinations are never overwritten. Serialized fit objects embed parsed draws and cached MCMC summary statistics. Only unserialize files you trust. Serialized objects do not embed a CmdStan installation or provide standalone generated quantities without their CSV files.

Console output is buffered until processes finish. `show_messages` controls stdout and `show_exceptions` controls stderr (defaults to the value of `show_messages`). Complete logs are retained in `output()`. A process failure throws `ProcessException` and cancels other running chains. Set `timeout` to bound each process in seconds.
