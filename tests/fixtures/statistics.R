args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages({ library(posterior); library(loo) })
input <- jsonlite::fromJSON(args[[1]], simplifyVector = TRUE)
raw <- jsonlite::fromJSON(args[[1]], simplifyVector = FALSE)
quiet <- function(expr) suppressWarnings(suppressMessages(expr))
na_null <- function(x) if (length(x) == 1 && is.na(x)) NULL else x

# Summary measures: chains[[variable]] is a chains x iterations matrix.
variables <- names(input$chains)
arr <- array(NA_real_, dim = c(ncol(input$chains[[1]]), nrow(input$chains[[1]]), length(variables)), dimnames = list(NULL, NULL, variables))
for (v in variables) arr[, , v] <- t(input$chains[[v]])
arr <- as_draws_array(arr)
summary_default <- quiet(as.data.frame(summarise_draws(arr)))
summary_all <- quiet(as.data.frame(summarise_draws(arr, default_summary_measures(), default_convergence_measures(), default_mcse_measures(), "rhat_basic", "ess_basic", "ess_mean", "ess_sd", "ess_median", "ess_quantile")))
summary_probs <- quiet(as.data.frame(summarise_draws(arr, ~quantile2(.x, probs = input$probs), ~mcse_quantile(.x, probs = input$probs), ~ess_quantile(.x, probs = input$probs))))

# Pareto smoothing.
smooth <- function(x, ...) { s <- quiet(pareto_smooth(x, return_k = TRUE, extra_diags = TRUE, ...)); list(x = as.numeric(s$x), diagnostics = lapply(s$diagnostics, na_null)) }
pareto <- list(
  both = smooth(input$smooth),
  right = smooth(input$smooth, tail = "right", r_eff = 1),
  left = smooth(input$smooth, tail = "left", r_eff = 0.8),
  small = smooth(input$smooth_small, tail = "right"),
  log_weights = smooth(input$log_weights, are_log_weights = TRUE),
  khat = quiet(pareto_khat(input$smooth)),
  gpdfit = posterior::gpdfit(input$gpd)
)

# PSIS, relative efficiency, LOO and WAIC exactly as CmdStanMCMC$loo(r_eff = TRUE).
ll <- input$log_lik
r_eff <- loo::relative_eff(exp(sweep(ll, 3, apply(ll, 3, max), FUN = "-")))
psis_out <- quiet(loo::psis(-ll, r_eff = r_eff))
psis_vector <- quiet(loo::psis(input$smooth_small, r_eff = 1))
loo_out <- quiet(loo::loo.array(ll, r_eff = r_eff))
loo_noreff <- quiet(loo::loo.array(ll, r_eff = NULL))
waic_out <- quiet(loo::waic(ll))

# draws_rvars dimensions and values (draws_of flattened column-major).
rv_df <- as_draws_df(as.data.frame(lapply(raw$rvars, unlist), check.names = FALSE))
rv <- as_draws_rvars(rv_df)
rvars <- lapply(names(rv), function(n) list(name = n, dim = dim(rv[[n]]), nchains = nchains(rv[[n]]), values = as.vector(draws_of(rv[[n]]))))

# Init dimension matching through process_init.draws (no resampling when rows <= chains).
model_variables <- list(parameters = raw$parameters)
init_json <- function(df, n) { paths <- quiet(cmdstanr:::process_init(as_draws_df(as.data.frame(lapply(df, unlist), check.names = FALSE)), num_procs = n, model_variables = model_variables, warn_partial = FALSE)); lapply(paths, function(p) paste(readLines(p), collapse = "\n")) }
inits <- list(two = init_json(raw$init_draws, 2), recycled = init_json(raw$init_one, 3))

jsonlite::write_json(list(
  summary_default = summary_default, summary_all = summary_all, summary_probs = summary_probs, pareto = pareto,
  relative_eff = r_eff, psis = list(log_weights = weights(psis_out), pareto_k = psis_out$diagnostics$pareto_k, n_eff = psis_out$diagnostics$n_eff),
  psis_vector = list(log_weights = as.numeric(weights(psis_vector)), pareto_k = psis_vector$diagnostics$pareto_k, n_eff = psis_vector$diagnostics$n_eff),
  loo = list(estimates = loo_out$estimates, pointwise = loo_out$pointwise, pareto_k = loo_out$diagnostics$pareto_k, n_eff = loo_out$diagnostics$n_eff),
  loo_noreff = list(estimates = loo_noreff$estimates, pointwise = loo_noreff$pointwise, n_eff = loo_noreff$diagnostics$n_eff),
  waic = list(estimates = waic_out$estimates, pointwise = waic_out$pointwise),
  rvars = rvars, inits = inits
), args[[2]], digits = NA, auto_unbox = TRUE, na = "null", matrix = "rowmajor")
