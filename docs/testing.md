---
layout: page
title: Testing and GitHub Pages
---

## Local verification

```sh
composer install
composer validate --strict
composer lint
composer test
export CMDSTAN=/absolute/path/to/cmdstan-2.40.0
composer test:integration
composer test:compatibility   # additionally needs R + cmdstanr, posterior, loo, bayesplot, jsonlite, Rcpp, RcppEigen
```

The unit suite does not need CmdStan. Moment-matching tests also need the `php_stats` extension with its posterior-draw kernels (for example `php -d extension=/path/to/libphp_stats.dylib vendor/bin/phpunit`) and skip without it. It verifies JSON shapes and invalid data, CSV comments/warmup/special numbers, draw layouts, summaries, diagnostics, serialization, option validation, process argument isolation, pipe draining, timeouts, cancellation, and bounded execution. It uses real PHP subprocesses for process behavior.

The integration suite compiles the bundled models and invokes real CmdStan methods. It compares Bernoulli posterior estimates with their analytic mean, checks R-hat and ESS, verifies deterministic seeds with parallel chains, confirms modes and draw counts, and exercises generated quantities, fixed parameters, syntax/formatting, gradient checking, log density, input errors, metrics, and auxiliary files. It also builds FFI libraries for model methods and exposed functions, initializes chains from fits, runs the example models, and computes LOO with and without moment matching. Tests that need FFI skip when it is unavailable. If CmdStan is not available, PHPUnit skips these tests; CI explicitly installs CmdStan so they execute there.

Tests use small models and a modest number of draws. They do not constitute a full upstream CmdStanR conformance suite, do not test a real MPI cluster or OpenCL hardware, and do not establish native Windows support. The package's [compatibility contract]({{ '/compatibility/' | relative_url }}) lists remaining gaps.

The compatibility suite runs the same algorithms with CmdStanR and PHP using identical seeds and precision, then compares individual parameter/log-density draws, approximate densities, descriptive summaries, R-hat, bulk/tail ESS, and MCMC diagnostics. MCMC comparisons include even and odd chain lengths. Further comparisons cover CSV metadata keys, in-process log densities, gradients, Hessians, constrain/unconstrain results, exposed Stan functions, PSIS, LOO, LOO moment matching, WAIC, relative efficiency, and posterior summary measures. Moment matching is compared with `loo::loo_moment_match.default()` on analytic regression posteriors, both unmodified and with loo's split step corrected as documented, and with CmdStanR's `loo(moment_match = TRUE)` on a same-seed fit. The values behind the figures are compared with R and bayesplot. R, CmdStanR, posterior, loo, bayesplot, jsonlite, Rcpp, and RcppEigen are needed. These tests skip when their prerequisites are absent.

The moment-matching reference was verified with loo 2.10.1.9000 and CmdStanR 0.9.0. The isolated R reference in `tests/fixtures/moment_match.R` records the package version and contains the explicit split-inverse and two-half efficiency corrections. Transform-order preservation also has an independent regression test.

## Continuous integration

`.github/workflows/tests.yml` runs unit tests on PHP 8.2, 8.3, and 8.4. A separate integration matrix builds CmdStan 2.37 and 2.40 on Linux, runs the real-model suite, and caches each toolchain. The integration jobs enable PHP's FFI extension and render every figure kind to SVG, and one to PNG, with a downloaded vl-convert binary. The 2.40 job additionally installs the reference R packages and runs the compatibility suite. Installation is performed using this package's installer to exercise its download/build path. Compilation on small CI machines is memory-intensive; integration uses two make jobs.

The current CI jobs do not install `php_stats`, so moment-matching tests skip there. Native coverage requires a CI build of the extension containing all three draw kernels. Local native tests must use a PHP executable matching the extension module ABI; the currently tested development build loads with Homebrew PHP 8.5, not Herd PHP 8.4.

## Documentation site

All site content is under `docs/`, using Jekyll and the Minima theme. `.github/workflows/pages.yml` builds the site on pull requests and deploys it from main or a manual dispatch on main. Deployment uses GitHub's Pages artifact and environment; no token beyond `GITHUB_TOKEN` is needed.

After pushing this repository through your normal workflow, select **Settings → Pages → Build and deployment → Source → GitHub Actions**. The workflow then handles the build and deployment. Repository settings cannot be enabled by writing files alone, and no remote publication has been performed in this workspace.

To preview locally with Ruby 3.1+:

```sh
cd docs
bundle install
bundle exec jekyll serve
```

For a project-site path, build with `bundle exec jekyll build --baseurl /repository-name`. Navigation uses Jekyll's `relative_url` filter, so it works both at a domain root and a repository subpath. The Pages workflow obtains the correct base path from `actions/configure-pages`.
