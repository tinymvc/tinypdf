#!/usr/bin/env python3
"""Replay failing font subsets under the platform debugger on release CI."""
import json
import pathlib
import struct
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
for name, text, index in [('color-formats/noto_handwriting-picosvg.ttf', '✍🏽', 0), ('formats/Latin.ttc', 'Cubic office café CFF2 0123456789', 1), ('notocoloremoji/NotoColorEmoji-Regular.ttf', '👨‍👩‍👧‍👦 🇧🇩 👍🏽', 0)]:
    path = ROOT / 'tests/fonts' / name
    if not path.exists():
        continue
    helper = ROOT / 'native/build/tinypdf-text'
    process = subprocess.Popen([str(helper)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    packets = bytearray()
    sequence = 0
    def call(op, **args):
        global sequence
        sequence += 1
        body = json.dumps(dict(protocol=1, id=sequence, op=op, **args), ensure_ascii=False).encode()
        packet = struct.pack('!I', len(body)) + body
        packets.extend(packet)
        process.stdin.write(packet)
        process.stdin.flush()
        header = process.stdout.read(4)
        if len(header) != 4:
            raise RuntimeError('Native process exited: ' + process.stderr.read().decode(errors='replace'))
        response = json.loads(process.stdout.read(struct.unpack('!I', header)[0]))
        if 'error' in response:
            raise RuntimeError(response['error'])
        return response['result']
    try:
        font = call('font', path=str(path), faceIndex=index)['font']
        layout = call('layout', text=text, spans=[dict(start=0, end=len(text.encode()), item=0, style=dict(fonts=[font], size=24))])
        gids = list(dict.fromkeys(glyph['gid'] for line in layout['lines'] for run in line['items'] for glyph in run['glyphs']))
        call('subset', font=font, glyphs=[dict(cid=i+1, gid=gid) for i, gid in enumerate(gids)])
        print('Subset succeeded:', name)
    except Exception as error:
        print(name, error, flush=True)
        with tempfile.TemporaryDirectory() as directory:
            request = pathlib.Path(directory) / 'requests'
            request.write_bytes(packets)
            subprocess.run(['lldb', '--batch', '-o', f'process launch -i {request} -o {directory}/responses', '-o', 'thread backtrace all', str(helper)], timeout=90)
    finally:
        process.stdin.close()
        process.wait(timeout=5)
