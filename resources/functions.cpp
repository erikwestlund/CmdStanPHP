// Uniform C ABI for stanc --standalone-functions wrappers, included after the generated header
// by CmdStan\StanFunctions. Values travel as tagged double buffers:
//   real: 0 v | int: 1 v | list: 2 n item_1 ... item_n
// Vectors and row vectors are lists, matrices are lists of rows, complex numbers are [re, im]
// and tuples are lists of their elements. Input is "n_args arg_1 ... arg_n"; output is one value
// (empty for void). Status is 0 on success, 2 for undecodable arguments, 1 for Stan errors.
// Like CmdStanR, one shared RNG is reseeded before every _rng call.
#include <stan/version.hpp>
#if STAN_MAJOR == 2 && STAN_MINOR >= 35
#include <stan/services/util/create_rng.hpp>
#else
#include <boost/random/additive_combine.hpp>
namespace stan { using rng_t = boost::ecuyer1988; }
#endif
#include <climits>
#include <cmath>
#include <complex>
#include <cstdlib>
#include <cstring>
#include <functional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <tuple>
#include <type_traits>
#include <utility>
#include <vector>

namespace cmdstanphp {
struct reader {
  const double* p;
  const double* end;
  double next() {
    if (p >= end) { throw std::invalid_argument("unexpected end of input"); }
    return *p++;
  }
  size_t list() {
    if (next() != 2) { throw std::invalid_argument("expected a list"); }
    double n = next();
    if (n < 0 || n != std::floor(n)) { throw std::invalid_argument("invalid list length"); }
    return static_cast<size_t>(n);
  }
};
using writer = std::vector<double>;
inline void write_list(writer& w, size_t n) { w.push_back(2); w.push_back(static_cast<double>(n)); }

template <typename T> struct codec;
template <> struct codec<double> {
  static double read(reader& r) {
    double tag = r.next();
    if (tag != 0 && tag != 1) { throw std::invalid_argument("expected a real number"); }
    return r.next();
  }
  static void write(writer& w, double v) { w.push_back(0); w.push_back(v); }
};
template <> struct codec<int> {
  static int read(reader& r) {
    double tag = r.next();
    if (tag != 0 && tag != 1) { throw std::invalid_argument("expected an integer"); }
    double v = r.next();
    if (!(v == std::floor(v)) || v < INT_MIN || v > INT_MAX) { throw std::invalid_argument("expected an integer"); }
    return static_cast<int>(v);
  }
  static void write(writer& w, int v) { w.push_back(1); w.push_back(v); }
};
template <> struct codec<std::complex<double>> {
  static std::complex<double> read(reader& r) {
    if (r.p < r.end && *r.p != 2) { return {codec<double>::read(r), 0.0}; }
    if (r.list() != 2) { throw std::invalid_argument("expected a complex number as [re, im]"); }
    double re = codec<double>::read(r);
    return {re, codec<double>::read(r)};
  }
  static void write(writer& w, const std::complex<double>& v) { write_list(w, 2); codec<double>::write(w, v.real()); codec<double>::write(w, v.imag()); }
};
template <typename S, int R, int C, int O, int MR, int MC> struct codec<Eigen::Matrix<S, R, C, O, MR, MC>> {
  using M = Eigen::Matrix<S, R, C, O, MR, MC>;
  static M read(reader& r) {
    size_t n = r.list();
    if (R == 1 || C == 1) {
      M m(R == 1 ? 1 : n, R == 1 ? n : 1);
      for (size_t i = 0; i < n; ++i) { m(i) = codec<S>::read(r); }
      return m;
    }
    std::vector<std::vector<S>> rows(n);
    size_t cols = 0;
    for (size_t i = 0; i < n; ++i) {
      size_t k = r.list();
      if (i > 0 && k != cols) { throw std::invalid_argument("matrix rows must have equal lengths"); }
      cols = k;
      for (size_t j = 0; j < k; ++j) { rows[i].push_back(codec<S>::read(r)); }
    }
    M m(n, cols);
    for (size_t i = 0; i < n; ++i) { for (size_t j = 0; j < cols; ++j) { m(i, j) = rows[i][j]; } }
    return m;
  }
  static void write(writer& w, const M& m) {
    if (R == 1 || C == 1) {
      write_list(w, m.size());
      for (Eigen::Index i = 0; i < m.size(); ++i) { codec<S>::write(w, m(i)); }
      return;
    }
    write_list(w, m.rows());
    for (Eigen::Index i = 0; i < m.rows(); ++i) {
      write_list(w, m.cols());
      for (Eigen::Index j = 0; j < m.cols(); ++j) { codec<S>::write(w, m(i, j)); }
    }
  }
};
template <typename T> struct codec<std::vector<T>> {
  static std::vector<T> read(reader& r) {
    size_t n = r.list();
    std::vector<T> v;
    v.reserve(n);
    for (size_t i = 0; i < n; ++i) { v.push_back(codec<T>::read(r)); }
    return v;
  }
  static void write(writer& w, const std::vector<T>& v) {
    write_list(w, v.size());
    for (const auto& x : v) { codec<T>::write(w, x); }
  }
};
template <typename... Ts> struct codec<std::tuple<Ts...>> {
  static std::tuple<Ts...> read(reader& r) {
    if (r.list() != sizeof...(Ts)) { throw std::invalid_argument("expected a tuple with " + std::to_string(sizeof...(Ts)) + " elements"); }
    return std::tuple<Ts...>{codec<Ts>::read(r)...};
  }
  static void write(writer& w, const std::tuple<Ts...>& t) {
    write_list(w, sizeof...(Ts));
    std::apply([&](const auto&... x) { (codec<std::decay_t<decltype(x)>>::write(w, x), ...); }, t);
  }
};

struct context {
  reader in;
  std::ostringstream msgs;
  stan::rng_t& rng;
  int argument = 0;
};
template <typename A> struct arg {
  using type = std::decay_t<A>;
  static constexpr int count = 1;
  static type get(context& c) {
    ++c.argument;
    try { return codec<type>::read(c.in); }
    catch (const std::invalid_argument& e) { throw std::invalid_argument("Argument " + std::to_string(c.argument) + ": " + e.what()); }
  }
};
template <> struct arg<std::ostream*> {
  using type = std::ostream*;
  static constexpr int count = 0;
  static type get(context& c) { return &c.msgs; }
};
template <> struct arg<stan::rng_t&> {
  using type = std::reference_wrapper<stan::rng_t>;
  static constexpr int count = 0;
  static type get(context& c) { return std::ref(c.rng); }
};

inline stan::rng_t& rng() { static stan::rng_t r(1); return r; }
inline char* copy_string(const std::string& s) {
  char* out = static_cast<char*>(std::malloc(s.size() + 1));
  if (out != nullptr) { std::memcpy(out, s.c_str(), s.size() + 1); }
  return out;
}

template <typename R, typename... A>
int call(R (*f)(A...), const double* in, int in_len, unsigned int seed, double** out, int* out_len, char** printed, char** err) {
  context c{reader{in, in + in_len}, {}, rng()};
  *out = nullptr; *out_len = 0; *printed = nullptr; *err = nullptr;
  bool decoding = true;
  try {
    constexpr int expected = (arg<A>::count + ... + 0);
    double n = c.in.next();
    if (n != expected) { throw std::invalid_argument("Expected " + std::to_string(expected) + " argument(s), but " + std::to_string(static_cast<long long>(n)) + " were provided."); }
    if ((std::is_same_v<A, stan::rng_t&> || ...)) { c.rng.seed(seed); }
    std::tuple<typename arg<A>::type...> args{arg<A>::get(c)...};
    if (c.in.p != c.in.end) { throw std::invalid_argument("Unexpected trailing input."); }
    decoding = false;
    writer w;
    if constexpr (std::is_void_v<R>) { std::apply(f, args); }
    else { codec<std::decay_t<R>>::write(w, std::apply(f, args)); }
    if (!w.empty()) {
      *out = static_cast<double*>(std::malloc(w.size() * sizeof(double)));
      if (*out == nullptr) { throw std::bad_alloc(); }
      std::memcpy(*out, w.data(), w.size() * sizeof(double));
      *out_len = static_cast<int>(w.size());
    }
    if (!c.msgs.str().empty()) { *printed = copy_string(c.msgs.str()); }
    return 0;
  } catch (const std::exception& e) {
    std::string extra = c.msgs.str();
    *err = copy_string(extra.empty() ? std::string(e.what()) : std::string(e.what()) + "\n" + extra);
    return decoding ? 2 : 1;
  } catch (...) {
    *err = copy_string("Unknown C++ exception.");
  }
  return 1;
}
}  // namespace cmdstanphp

extern "C" void cmdstanphp_free(void* p) { std::free(p); }
