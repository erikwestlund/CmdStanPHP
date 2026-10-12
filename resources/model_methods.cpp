// C ABI over a stanc-generated model, included after the model header by CmdStan\NativeBuild.
// Semantics follow CmdStanR's model_methods.cpp: densities drop constants (propto),
// the Hessian uses finite differences of autodiff gradients, and one seeded RNG
// persists across constrain calls. Every C++ exception is caught at the boundary.
#include <stan/model/log_prob_grad.hpp>
#include <stan/model/log_prob_propto.hpp>
#include <stan/math/rev/functor/finite_diff_hessian_auto.hpp>
#include <stan/io/empty_var_context.hpp>
#include <stan/version.hpp>
#if __has_include(<stan/io/json/json_data.hpp>)
#include <stan/io/json/json_data.hpp>
using cmdstanphp_json_data = stan::json::json_data;
#else
#include <cmdstan/io/json/json_data.hpp>
using cmdstanphp_json_data = cmdstan::json::json_data;
#endif
#if STAN_MAJOR == 2 && STAN_MINOR >= 35
#include <stan/services/util/create_rng.hpp>
#else
#include <boost/random/additive_combine.hpp>
namespace stan { using rng_t = boost::ecuyer1988; }
#endif
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <memory>
#include <sstream>
#include <string>
#include <vector>

struct cmdstanphp_model {
  stan::model::model_base* model;
  stan::rng_t rng;
  cmdstanphp_model(stan::model::model_base* m, unsigned int seed) : model(m), rng(seed) {}
  ~cmdstanphp_model() { delete model; }
};

namespace cmdstanphp {
inline char* copy_string(const std::string& s) {
  char* out = static_cast<char*>(std::malloc(s.size() + 1));
  if (out != nullptr) { std::memcpy(out, s.c_str(), s.size() + 1); }
  return out;
}
inline void set_error(char** err, const std::string& message, const std::ostringstream& msgs) {
  if (err == nullptr) { return; }
  std::string extra = msgs.str();
  *err = copy_string(extra.empty() ? message : message + "\n" + extra);
}
template <typename F>
inline int guard(char** err, F&& f) {
  std::ostringstream msgs;
  try { f(msgs); return 0; }
  catch (const std::exception& e) { set_error(err, e.what(), msgs); }
  catch (...) { set_error(err, "Unknown C++ exception.", msgs); }
  return 1;
}
inline std::string json_string(const std::string& s) {
  std::string out = "\"";
  for (char c : s) {
    if (c == '"' || c == '\\') { out += '\\'; out += c; }
    else if (static_cast<unsigned char>(c) < 0x20) { out += ' '; }
    else { out += c; }
  }
  return out + "\"";
}
inline std::string json_names(const std::vector<std::string>& names) {
  std::string out = "[";
  for (size_t i = 0; i < names.size(); ++i) { out += (i ? "," : "") + json_string(names[i]); }
  return out + "]";
}
}  // namespace cmdstanphp

extern "C" {

void cmdstanphp_free(void* p) { std::free(p); }

cmdstanphp_model* cmdstanphp_model_new(const char* data_file, unsigned int seed, char** err) {
  cmdstanphp_model* out = nullptr;
  cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    std::string path = data_file == nullptr ? "" : data_file;
    std::unique_ptr<stan::io::var_context> context;
    if (path.empty()) { context = std::make_unique<stan::io::empty_var_context>(); }
    else {
      std::ifstream stream(path);
      if (!stream.good()) { throw std::runtime_error("Cannot read data file: " + path); }
      context = std::make_unique<cmdstanphp_json_data>(stream);
    }
    stan::model::model_base* model = &new_model(*context, seed, &msgs);
    out = new cmdstanphp_model(model, seed);
  });
  return out;
}

void cmdstanphp_model_free(cmdstanphp_model* m) { delete m; }

// JSON: {"num_unconstrained":n,"blocks":[params,tparams,gqs],"names":[..],"dims":[[..]],"constrained":[..],"unconstrained":[..]}
char* cmdstanphp_model_info(cmdstanphp_model* m, char** err) {
  char* out = nullptr;
  cmdstanphp::guard(err, [&](std::ostringstream&) {
    std::vector<std::string> p, tp, all, constrained, unconstrained;
    std::vector<std::vector<size_t>> dims;
    m->model->get_param_names(p, false, false);
    m->model->get_param_names(tp, true, false);
    m->model->get_param_names(all, true, true);
    m->model->get_dims(dims, true, true);
    m->model->constrained_param_names(constrained, true, true);
    m->model->unconstrained_param_names(unconstrained, false, false);
    std::string json = "{\"num_unconstrained\":" + std::to_string(m->model->num_params_r());
    json += ",\"blocks\":[" + std::to_string(p.size()) + "," + std::to_string(tp.size() - p.size()) + "," + std::to_string(all.size() - tp.size()) + "]";
    json += ",\"names\":" + cmdstanphp::json_names(all) + ",\"dims\":[";
    for (size_t i = 0; i < dims.size(); ++i) {
      json += i ? ",[" : "[";
      for (size_t j = 0; j < dims[i].size(); ++j) { json += (j ? "," : "") + std::to_string(dims[i][j]); }
      json += "]";
    }
    json += "],\"constrained\":" + cmdstanphp::json_names(constrained) + ",\"unconstrained\":" + cmdstanphp::json_names(unconstrained) + "}";
    out = cmdstanphp::copy_string(json);
  });
  return out;
}

int cmdstanphp_log_density(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, char** err) {
  return cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    Eigen::VectorXd x = Eigen::Map<const Eigen::VectorXd>(theta, m->model->num_params_r());
    *lp = jacobian ? stan::model::log_prob_propto<true>(*m->model, x, &msgs) : stan::model::log_prob_propto<false>(*m->model, x, &msgs);
  });
}

int cmdstanphp_log_density_gradient(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, double* grad, char** err) {
  return cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    Eigen::VectorXd x = Eigen::Map<const Eigen::VectorXd>(theta, m->model->num_params_r()), g;
    *lp = jacobian ? stan::model::log_prob_grad<true, true>(*m->model, x, g, &msgs) : stan::model::log_prob_grad<true, false>(*m->model, x, g, &msgs);
    Eigen::Map<Eigen::VectorXd>(grad, g.size()) = g;
  });
}

int cmdstanphp_log_density_hessian(cmdstanphp_model* m, const double* theta, int jacobian, double* lp, double* grad, double* hess, char** err) {
  return cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    const size_t n = m->model->num_params_r();
    Eigen::VectorXd x = Eigen::Map<const Eigen::VectorXd>(theta, n), g;
    Eigen::MatrixXd h;
    auto f = [&](auto&& v) { return jacobian ? m->model->log_prob<true, true>(v, &msgs) : m->model->log_prob<true, false>(v, &msgs); };
    stan::math::internal::finite_diff_hessian_auto(f, x, *lp, g, h);
    Eigen::Map<Eigen::VectorXd>(grad, n) = g;
    Eigen::Map<Eigen::MatrixXd>(hess, n, n) = h;
  });
}

int cmdstanphp_param_constrain(cmdstanphp_model* m, const double* theta, int tparams, int gqs, double* out, int capacity, char** err) {
  return cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    Eigen::VectorXd x = Eigen::Map<const Eigen::VectorXd>(theta, m->model->num_params_r()), values;
    m->model->write_array(m->rng, x, values, tparams != 0, gqs != 0, &msgs);
    if (values.size() != capacity) { throw std::length_error("Unexpected number of constrained values: " + std::to_string(values.size())); }
    Eigen::Map<Eigen::VectorXd>(out, values.size()) = values;
  });
}

int cmdstanphp_param_unconstrain(cmdstanphp_model* m, const double* constrained, int size, double* out, char** err) {
  return cmdstanphp::guard(err, [&](std::ostringstream& msgs) {
    Eigen::VectorXd x = Eigen::Map<const Eigen::VectorXd>(constrained, size), values;
    m->model->unconstrain_array(x, values, &msgs);
    Eigen::Map<Eigen::VectorXd>(out, values.size()) = values;
  });
}

void cmdstanphp_seed(cmdstanphp_model* m, unsigned int seed) { m->rng.seed(seed); }

}  // extern "C"
