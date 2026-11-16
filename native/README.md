# TinyPDF native helper 1.1.0

The helper is required for every document. PHP launches it once using an argument-array `proc_open()` call and retains fonts and instances until the document is destroyed. The helper never downloads fonts or accesses the network.

## Dependencies and build

C++17, CMake 3.21+, pkg-config, HarfBuzz 14.2.1+ (including subset and Cairo), ICU 78.3+ with data, FriBidi 1.0.16+, FreeType with WOFF/WOFF2/Brotli support, Cairo 1.18+ with PNG, and librsvg 2.52+ are required. No PHP FFI extension is used.

If the appropriate development libraries are already installed:

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

Linux release builds target glibc 2.39 (Ubuntu 24.04), x64 and ARM64. Other libc environments, including musl, need a source build. The CI matrix also defines macOS Intel/Apple Silicon and Windows x64 jobs. The CI jobs build and test the helper on each target before creating an archive.

## Protocol 1

Each request/response is a four-byte unsigned big-endian byte length followed by UTF-8 JSON. Maximum frame size: 64 MiB. Maximum paragraph: 1 MiB. stdout carries frames exclusively; stderr carries diagnostics. Requests contain `protocol:1`, a monotonically increasing integer `id`, and `op`. Responses echo protocol/id and contain `result` or `error:{code,message}`.

Operations:

- `capabilities`: versions, Unicode version, required operations and frame limit.
- `font`: local path, zero-based face index and axis coordinates; returns immutable font-instance ID and native metadata.
- `analyze`: UTF-8/UTF-16/character offset maps, grapheme and line boundaries, bidi levels and visual map.
- `layout`: logical paragraph, contiguous styled source ranges, font stacks, language, direction and available inline width; returns line runs with glyph IDs, byte clusters, advances, offsets, ink bounds and original Unicode.
- `color`: font instance, shaped glyph ID, point size, DPI and foreground; returns transparent pixel planes and placement bounds.
- `subset`: font instance and document CID/glyph pairs; returns a static subset, widths and original-to-subset mapping.
- `batch`: up to 4096 non-nested operations.

The PHP client checks capabilities, IDs, framing and deadlines, drains stderr, and terminates failed helpers. An unavailable process API, missing/incompatible executable, missing fonts, invalid UTF-8, unsupported font data or native failure causes an exception. There is no legacy shaping fallback.

ICU still supplies all versioned Unicode properties. FriBidi supplies bidi resolution because the tested ICU 78.3 resolver fails nested overrides/isolate cases; the combined pipeline passes the complete pinned Unicode 17 test files. HarfBuzz handles glyph mirroring and mark positioning after bidi ordering; Unicode source text is never reversed or converted to presentation forms.

## Distribution

`package.py` bundles shared dependencies, ICU data, discovered licenses, source-lock metadata and SHA-256 inventory. Read `THIRD_PARTY.md` and audit every packaged dependency's license/source obligations before public redistribution. CI uploads candidates only; it does not publish releases or tag the PHP package.
