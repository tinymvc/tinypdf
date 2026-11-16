#!/usr/bin/env python3
"""Require logical Unicode recovery in MuPDF, PDFium and Poppler."""
import json
import os
import pathlib
import re
import shutil
import subprocess

import pymupdf
import pypdfium2

ROOT = pathlib.Path(__file__).resolve().parents[1]


def canonical(text, poppler=False):
    if poppler:
        # pdftotext emits visual RTL order inside explicit directional spans.
        text = re.sub('\u202b([^\u202c]*)\u202c', lambda match: match[1][::-1], text)
    text = re.sub('[\u200e\u200f\u202a-\u202e\u2066-\u2069]', '', text)
    return re.sub(r'\s+', '', text)


def verify(path):
    extractor = os.environ.get('PDFTOTEXT') or shutil.which('pdftotext')
    assert extractor, 'Poppler pdftotext is required'
    cases = json.loads(subprocess.check_output(['php', str(ROOT / 'tests/extraction.php'), str(path)], cwd=ROOT))
    mupdf = pymupdf.open(path)
    pdfium = pypdfium2.PdfDocument(path)
    result = subprocess.run([extractor, '-raw', '-enc', 'UTF-8', str(path), '-'], capture_output=True, check=True)
    assert not result.stderr, result.stderr.decode(errors='replace')
    poppler_pages = result.stdout.decode().split('\f')[:-1]
    assert len(mupdf) == len(pdfium) == len(poppler_pages) == len(cases)
    for index, case in enumerate(cases):
        expected = canonical(case['text'])
        textpage = pdfium[index].get_textpage()
        texts = {'mupdf': mupdf[index].get_text(), 'pdfium': textpage.get_text_bounded(), 'poppler': poppler_pages[index]}
        for engine, text in texts.items():
            assert canonical(text, engine == 'poppler') == expected, (engine, index, case['text'], text)
        # Search must find the complete phrases, with a useful selection area.
        if index in (0, 11):
            rectangles = mupdf[index].search_for(case['text'])
            assert rectangles and sum(rect.width for rect in rectangles) > 60, (index, rectangles)
            search = textpage.search(case['text'])
            assert search.get_next() is not None, ('PDFium search', index)
            search.close()
        textpage.close()
    assert not pymupdf.TOOLS.mupdf_warnings(), 'Invalid PDF/font data in extraction fixtures'
    mupdf.close()
    pdfium.close()
    return {'cases': len(cases), 'readers': ['MuPDF', 'PDFium', 'Poppler'], 'failures': 0}


if __name__ == '__main__':
    path = ROOT / 'output/pdf/tinypdf-1.1.0-extraction.pdf'
    path.parent.mkdir(parents=True, exist_ok=True)
    print(json.dumps(verify(path)))
