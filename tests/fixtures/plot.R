args <- commandArgs(trailingOnly = TRUE)
input <- jsonlite::fromJSON(args[[1]], simplifyVector = TRUE)
x <- input$x
bw <- bw.nrd0(x)
draws <- posterior::draws_array(a = matrix(x, ncol = 2), b = matrix(input$ties, ncol = 2))
intervals <- as.data.frame(bayesplot::mcmc_intervals_data(draws, prob = 0.5, prob_outer = 0.9, point_est = "median"))
jsonlite::write_json(list(
  bandwidth = bw,
  density = sapply(input$at, function(a) mean(dnorm((a - x) / bw)) / bw),
  acf = acf(x, lag.max = 20, plot = FALSE)$acf[, 1, 1],
  ranks = rank(input$ties),
  intervals = intervals[, c("parameter", "ll", "l", "m", "h", "hh")]
), args[[2]], digits = NA, auto_unbox = TRUE)
