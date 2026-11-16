# TinyPDF native helper 1.2.0

The helper is required for every document. PHP launches it once using an argument-array `proc_open()` call and retains fonts and instances until the document is destroyed. The helper never downloads fonts. Windows uses an authenticated loopback socket because PHP process pipes cannot enforce nonblocking reads; Linux/macOS use stdin/stdout.

## Dependencies and build

C++17, CMake 3.21+, pkg-config, HarfBuzz 14.2.1+ (including subset and Cairo), ICU 78.3+ with data, FriBidi 1.0.16+, FreeType with WOFF/WOFF2/Brotli support, Cairo 1.18+ with PNG, and librsvg 2.52+ are required. No PHP FFI extension is used.

With the development libraries installed, build and verify from your application's project root:

```sh
php vendor/bin/tinypdf-install.php
```

In a source checkout, run `composer install` followed by `php bin/tinypdf-install.php`. The installer runs these commands for you:

```sh
cmake -S native -B native/build -DCMAKE_BUILD_TYPE=Release
cmake --build native/build --parallel
php bin/tinypdf-check.php
```

The core native source releases and hashes are in `sources.lock.json`. Python 3.12+, Meson 1.8.3, Ninja 1.13.0, make and a C/C++ toolchain can build those engines into an isolated prefix:

```sh
python3 native/build-dependencies.py
export PKG_CONFIG_PATH="$PWD/native/prefix/lib/pkgconfig:$PKG_CONFIG_PATH"
export PATH="$PWD/native/prefix/bin:$PATH"
export LD_LIBRARY_PATH="$PWD/native/prefix/lib:$LD_LIBRARY_PATH"
cmake -S native -B native/build -DCMAKE_BUILD_TYPE=Release
cmake --build native/build --parallel
```

Cairo, librsvg, libpng, Brotli and their platform dependencies must already be installed. On Ubuntu 24.04 install `build-essential cmake pkg-config libcairo2-dev libfreetype-dev libpng-dev librsvg2-dev libbrotli-dev patchelf`. On macOS use equivalent Homebrew/MacPorts development packages. On Windows use the MSYS2 UCRT64 toolchain and its CMake, pkgconf, Cairo, FreeType, libpng, librsvg and Brotli packages; build from a UCRT64 shell. The checked-in workflow describes the concrete jobs.

`cmake --install native/build --prefix /desired/location` installs the executable. For a relocatable bundle, run `python3 native/package.py`, then run the diagnostic against the resulting `dist/.../bin/tinypdf-text`. Copy **both** `bin/` and `lib/` when installing a macOS/Linux bundle. Windows DLLs live alongside the executable.

Linux x64/ARM64 archives include a private glibc runtime and loader, so they do not require the host distribution to provide a matching glibc. Use the `bin/tinypdf-text` launcher; it leaves system libraries unchanged. The Linux compatibility checks exercise AlmaLinux 8, Ubuntu 20.04, Alpine, and Arch Linux userlands. Other CPU architectures need a source build. The CI matrix also defines macOS Intel/Apple Silicon and Windows x64 jobs. The CI jobs build and test the helper on each target before creating an archive.

## Distribution

`package.py` bundles shared dependencies, ICU data, discovered licenses, source-lock metadata and SHA-256 inventory. Read `THIRD_PARTY.md` and audit every packaged dependency's license/source obligations before public redistribution. CI uploads candidates only; it does not publish releases or tag the PHP package.
