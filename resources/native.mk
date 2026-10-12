# Loaded after CmdStan's makefile by CmdStan\NativeBuild so make expands CmdStan's own
# toolchain variables (including make/local). Builds one shared library from one source:
#   make -C $CMDSTAN -f makefile -f native.mk CMDSTANPHP_SRC=/abs/x.cpp CMDSTANPHP_OUT=/abs/x.so cmdstanphp-native
ifeq ($(OS),Windows_NT)
CMDSTANPHP_PIC ?=
else
CMDSTANPHP_PIC ?= -fPIC
endif

.PHONY: cmdstanphp-native
cmdstanphp-native: $(CMDSTANPHP_OUT)

# tbbmalloc_proxy is not linked: it replaces malloc process-wide, which is unsafe inside PHP.
$(CMDSTANPHP_OUT): $(CMDSTANPHP_SRC) $(SUNDIALS_TARGETS) $(MPI_TARGETS) $(TBB_TARGETS)
	$(LINK.cpp) -shared $(CMDSTANPHP_PIC) $(CMDSTANPHP_SRC) $(LDLIBS) $(SUNDIALS_TARGETS) $(MPI_TARGETS) $(filter-out %tbbmalloc_proxy.dylib %tbbmalloc_proxy.so %tbbmalloc_proxy.so.2 %tbbmalloc_proxy.dll,$(TBB_TARGETS)) -o $(CMDSTANPHP_OUT)
