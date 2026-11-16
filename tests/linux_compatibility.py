#!/usr/bin/env python3
"""Exercise the private Linux runtime in independent distribution userlands."""
import os
import pathlib
import platform
import shlex
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
helper = next((ROOT / 'dist').glob('tinypdf-text-1.1.0-linux-*/bin/tinypdf-text'))
images = ['almalinux:8', 'ubuntu:20.04', 'alpine:3.22']
if platform.machine() in ('x86_64', 'amd64'):
    images.append('archlinux:latest')
with tempfile.TemporaryDirectory(prefix='tinypdf-linux-') as directory:
    wrapper = pathlib.Path(directory) / 'tinypdf-text'
    for image in images:
        subprocess.run(['docker', 'pull', image], check=True)
        command = ['docker', 'run', '--rm', '-i', '--network=none',
                   '-v', f'{ROOT}:{ROOT}:ro', '-w', str(ROOT), image, str(helper)]
        wrapper.write_text('#!/bin/sh\nexec ' + shlex.join(command) + ' "$@"\n')
        wrapper.chmod(0o755)
        env = os.environ.copy()
        env['TINYPDF_TEXT_HELPER'] = str(wrapper)
        print('Verifying Linux runtime in', image, flush=True)
        subprocess.run(['php', str(ROOT / 'bin/tinypdf-check.php'), str(wrapper)], env=env, cwd=ROOT, check=True)
        subprocess.run(['php', str(ROOT / 'tests/run.php'), 'FontFormats'], env=env, cwd=ROOT, check=True)
        subprocess.run(['python', str(ROOT / 'tests/rendering.py')], env=env, cwd=ROOT, check=True)
print('Linux distribution compatibility checks passed:', ', '.join(images))
