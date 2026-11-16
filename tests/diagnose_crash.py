#!/usr/bin/env python3
"""Print the crashing thread from native helper reports on macOS CI."""
import json
import pathlib
import time

folder = pathlib.Path.home() / 'Library/Logs/DiagnosticReports'
for attempt in range(5):
    paths = list(folder.glob('tinypdf-text*.ips'))
    if paths:
        break
    time.sleep(1)
for path in paths:
    raw = path.read_text()
    _, end = json.JSONDecoder().raw_decode(raw)
    report = json.loads(raw[end:])
    images = report.get('usedImages', [])
    print(path.name, report.get('exception'), report.get('termination'))
    for thread in report.get('threads', []):
        if thread.get('triggered'):
            for frame in thread.get('frames', [])[:20]:
                image = images[frame['imageIndex']]
                print(image.get('path'), frame.get('symbol'), frame.get('symbolLocation'), frame.get('imageOffset'))
