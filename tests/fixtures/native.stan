functions {
  real add(real x, int y) { return x + y; }
  vector scale(vector v, real a) { return a * v; }
  matrix outer(row_vector r, vector c) { return c * r; }
  array[] real twice(data array[] real x) { return to_array_1d(2 * to_vector(x)); }
  array[,] int pairs(int n) { array[n, 2] int out; for (i in 1:n) { out[i] = {i, -i}; } return out; }
  complex cmul(complex a, complex b) { return a * b; }
  real draw_rng(real mu) { return normal_rng(mu, 1); }
  real shifted_lpdf(real y, real mu) { return normal_lpdf(y | mu, 1); }
  real counts_lpmf(int k, real lambda) { return poisson_lpmf(k | lambda); }
}
data { int N; vector[N] y; }
parameters {
  real mu;
  real<lower=0> sigma;
  array[2, 3] real a;
  matrix[2, 3] m;
  simplex[3] s;
  cov_matrix[2] S;
}
transformed parameters { real<lower=0> s2 = square(sigma); }
model {
  y ~ normal(mu, sigma);
  mu ~ normal(0, 10);
  sigma ~ lognormal(0, 1);
  to_array_1d(a) ~ normal(0, 2);
  to_vector(m) ~ normal(1, 1);
  s ~ dirichlet([2, 3, 4]');
  S ~ wishart(4, identity_matrix(2));
}
generated quantities { real r = normal_rng(mu, sigma); array[2] int k = {poisson_rng(3), poisson_rng(5)}; }
