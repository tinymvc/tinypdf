#!/usr/bin/env python3
"""Check that Composer ships a small, installable package with its font manifest."""
import os
import json
import pathlib
import subprocess
import tempfile
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='tinypdf-source-') as directory:
    subprocess.run(['composer', 'archive', '--format=zip', '--dir=' + directory, '--file=tinypdf-1.1.0'], cwd=ROOT, check=True)
    archive = pathlib.Path(directory) / 'tinypdf-1.1.0.zip'
    assert archive.stat().st_size < 2_000_000, 'Source archive includes build artifacts'
    package = pathlib.Path(directory) / 'installed'
    with zipfile.ZipFile(archive) as source:
        names = source.namelist()
        assert not any(name.startswith(('native/build/', 'native/prefix/', 'native/downloads/', 'tests/', 'vendor/', 'dist/', 'output/')) for name in names)
        for required in ['assets/font-packs.json', 'assets/fonts/OFL.txt', 'native/sources.lock.json', 'src/HtmlPdf.php', 'bin/tinypdf-install.php']:
            assert required in names, required
        source.extractall(package)
    env = os.environ.copy()
    env['COMPOSER_ROOT_VERSION'] = '1.1.0'
    env['COMPOSER_HOME'] = str(pathlib.Path(directory) / 'composer-home')
    env['COMPOSER_CACHE_DIR'] = str(pathlib.Path(directory) / 'composer-cache')
    env.pop('TINYPDF_TEXT_HELPER', None)
    application = pathlib.Path(directory) / 'application with spaces'
    application.mkdir()
    (application / 'composer.json').write_text(json.dumps({
        'require': {'tinymvc/tinypdf': '1.1.0'},
        'repositories': [
            {'type': 'path', 'url': str(package), 'options': {
                'symlink': False, 'versions': {'tinymvc/tinypdf': '1.1.0'}}},
            {'packagist.org': False},
        ],
    }))
    subprocess.run(['composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins'],
                   cwd=application, env=env, check=True)
    installed = application / 'vendor/tinymvc/tinypdf'
    assert not (installed / 'vendor').exists() and not (installed / 'tests').exists()
    subprocess.run(['php', 'vendor/bin/tinypdf-install.php'], cwd=application, env=env, check=True)
    subprocess.run(['php', 'vendor/bin/tinypdf-check.php'], cwd=application, env=env, check=True)
    subprocess.run(['php', 'bin/tinypdf-check.php'], cwd=installed, env=env, check=True)
    invalid = subprocess.run(['php', 'bin/install-fonts.php', 'unknown-pack'], cwd=package, capture_output=True)
    assert invalid.returncode == 1 and b'Unknown font pack' in invalid.stderr, invalid.stderr
    print('Verified project-root installation from Composer archive:', archive.stat().st_size, 'bytes')
