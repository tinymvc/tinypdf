#!/usr/bin/env python3
"""Verify checksums and exercise an extracted runtime without build-library paths."""
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tarfile
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
archive = pathlib.Path(sys.argv[1]).resolve()
expected = archive.with_suffix(archive.suffix + '.sha256').read_text().split()[0]
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected
with tempfile.TemporaryDirectory(prefix='tinypdf-installed-') as directory:
    if archive.suffix == '.zip':
        with zipfile.ZipFile(archive) as package:
            package.extractall(directory)
    else:
        with tarfile.open(archive) as package:
            package.extractall(directory, filter='data')
    folder = next(pathlib.Path(directory).iterdir())
    manifest = json.loads((folder / 'manifest.json').read_text())
    assert manifest['version'] == '1.1.0' and manifest['library_licenses']
    for name, digest in manifest['files'].items():
        assert hashlib.sha256((folder / name).read_bytes()).hexdigest() == digest, name
    for library, notices in manifest['library_licenses'].items():
        assert notices and all((folder / notice).is_file() for notice in notices), library
    helper = folder / 'bin' / ('tinypdf-text.exe' if os.name == 'nt' else 'tinypdf-text')
    env = os.environ.copy()
    for key in ['LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH', 'DYLD_FALLBACK_LIBRARY_PATH']:
        env.pop(key, None)
    env['PATH'] = os.pathsep.join(part for part in env['PATH'].split(os.pathsep)
                                if str(ROOT / 'native/prefix') not in part)
    env['TINYPDF_TEXT_HELPER'] = str(helper)
    subprocess.run(['php', str(ROOT / 'bin/tinypdf-check.php'), str(helper)], env=env, cwd=ROOT, check=True)
    subprocess.run(['php', str(ROOT / 'tests/run.php')], env=env, cwd=ROOT, check=True)
    subprocess.run([sys.executable, str(ROOT / 'tests/rendering.py')], env=env, cwd=ROOT, check=True)
print('Verified extracted runtime:', archive.name)
