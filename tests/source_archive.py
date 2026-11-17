#!/usr/bin/env python3
"""Check that Composer ships a small, installable package with its font manifest."""
import os
import json
import pathlib
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or 'php'
COMPOSER = shutil.which('composer')
assert COMPOSER, 'Composer is required for the installation check'
_vm = re.search(r"public const VERSION = '([^']+)';", (ROOT / 'src/HtmlPdf.php').read_text())
VERSION = _vm[1] if _vm else '1.2.1'
with tempfile.TemporaryDirectory(prefix='tinypdf-source-') as directory:
    subprocess.run([COMPOSER, 'archive', '--format=zip', '--dir=' + directory, '--file=tinypdf-' + VERSION], cwd=ROOT, check=True)
    archive = pathlib.Path(directory) / f'tinypdf-{VERSION}.zip'
    assert archive.stat().st_size < 2_000_000, f'Source archive includes build artifacts: {archive.stat().st_size} bytes'
    package = pathlib.Path(directory) / 'installed'
    with zipfile.ZipFile(archive) as source:
        names = source.namelist()
        assert not any(name.startswith(('native/', 'tests/', 'vendor/', 'dist/', 'output/')) for name in names)
        for required in ['assets/font-packs.json', 'assets/fonts/OFL.txt', 'src/HtmlPdf.php', 'bin/tinypdf-install.php']:
            assert required in names, required
        source.extractall(package)
    env = os.environ.copy()
    env['COMPOSER_ROOT_VERSION'] = VERSION
    env['COMPOSER_HOME'] = str(pathlib.Path(directory) / 'composer-home')
    env['COMPOSER_CACHE_DIR'] = str(pathlib.Path(directory) / 'composer-cache')
    env.pop('TINYPDF_TEXT_HELPER', None)
    application = pathlib.Path(directory) / 'application with spaces'
    application.mkdir()
    (application / 'composer.json').write_text(json.dumps({
        'require': {'tinymvc/tinypdf': VERSION},
        'repositories': [
            {'type': 'path', 'url': str(package), 'options': {
                'symlink': False, 'versions': {'tinymvc/tinypdf': VERSION}}},
            {'packagist.org': False},
        ],
    }))
    subprocess.run([COMPOSER, 'install', '--no-interaction', '--no-scripts', '--no-plugins'],
                   cwd=application, env=env, check=True)
    installed = application / 'vendor/tinymvc/tinypdf'
    assert not (installed / 'vendor').exists() and not (installed / 'tests').exists()
    ext = '.zip' if os.name == 'nt' else '.tar.gz'
    matching = sorted([p for p in (ROOT / 'dist').glob(f'tinypdf-text-{VERSION}-*{ext}') if not p.name.endswith('.sha256')], reverse=True)
    if not matching:
        matching = sorted([p for p in (ROOT / 'dist').glob(f'tinypdf-text-*{ext}') if not p.name.endswith('.sha256')], reverse=True)
    if matching:
        env['TINYPDF_PREBUILT_PATH'] = str(matching[0])
    subprocess.run([PHP, 'vendor/bin/tinypdf-install.php'], cwd=application, env=env, check=True)
    subprocess.run([PHP, 'vendor/bin/tinypdf-check.php'], cwd=application, env=env, check=True)
    subprocess.run([PHP, 'bin/tinypdf-check.php'], cwd=installed, env=env, check=True)
    invalid = subprocess.run([PHP, 'bin/install-fonts.php', 'unknown-pack'], cwd=package, capture_output=True)
    assert invalid.returncode == 1 and b'Unknown font pack' in invalid.stderr, invalid.stderr
    print('Verified project-root installation from Composer archive:', archive.stat().st_size, 'bytes')
