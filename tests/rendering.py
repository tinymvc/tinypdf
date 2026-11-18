#!/usr/bin/env python3
"""Validate text recovery, embedded outlines and three independent PDF rasterizers."""
import io
import json
import os
import pathlib
import re
import shutil
import subprocess
import tempfile

import pymupdf
import pypdfium2
from fontTools.ttLib import TTFont
from pypdf import PdfReader
from extraction import canonical, verify as verify_extraction

ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = ROOT / 'output/pdf'
REFERENCE = ROOT / 'tests/reference'
TITLE = 'বাংলা ভাষায় নির্ভুল নথি তৈরি'
EMOJI = '😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽 ❤️'


def run(args):
    result = subprocess.run([str(x) for x in args], cwd=ROOT, capture_output=True, check=True)
    assert not result.stderr, result.stderr.decode(errors='replace')
    return result.stdout


def inspect_fonts(reader):
    checked = set()
    for page in reader.pages:
        for reference in page['/Resources']['/Font'].values():
            if reference.idnum in checked:
                continue
            checked.add(reference.idnum)
            font = reference.get_object()
            assert '/ToUnicode' in font
            if font['/Subtype'] == '/Type3':
                assert font['/CharProcs'] and font['/Resources']['/Font']
                continue
            descendant = font['/DescendantFonts'][0].get_object()
            descriptor = descendant['/FontDescriptor']
            if '/FontFile2' in descriptor:
                data = descriptor['/FontFile2'].get_data()
                subset = TTFont(io.BytesIO(data))
                assert 'fvar' not in subset and 'gvar' not in subset, 'Variable font was not frozen'
                mapping = descendant['/CIDToGIDMap'].get_data()
                gids = [int.from_bytes(mapping[i:i + 2], 'big') for i in range(0, len(mapping), 2)]
                assert all(gid < subset['maxp'].numGlyphs for gid in gids), 'Invalid subset glyph mapping'
            else:
                assert descriptor['/FontFile3']['/Subtype'] == '/CIDFontType0C'
                data = descriptor['/FontFile3'].get_data()
                assert data[0] == 1, 'CFF2 must be normalized to CFF1'
    return len(checked)


def difference(a, b):
    assert (a.width, a.height, a.n) == (b.width, b.height, b.n), 'Rendered page dimensions differ'
    # Normalize error by ink, not white page area, so missing text cannot pass.
    ink = sum(255 - x for x in a.samples)
    assert ink > 10000, 'Blank rendered page'
    return sum(abs(x - y) for x, y in zip(a.samples, b.samples)) / ink


def verify_css(poppler, extractor):
    path = OUT / 'tinypdf-1.2.2-css.pdf'
    run(['php', ROOT / 'tests/css_specimen.php', path])
    fonts = inspect_fonts(PdfReader(path, strict=True))
    with pymupdf.open(path) as document, pypdfium2.PdfDocument(path) as pdfium:
        assert len(document) == len(pdfium) == 1, 'CSS specimen must fit on one page'
        texts = [document[0].get_text(), pdfium[0].get_textpage().get_text_bounded(),
                 run([extractor, '-enc', 'UTF-8', path, '-']).decode()]
        for index, text in enumerate(texts):
            text = canonical(text, poppler=index == 2)
            for expected in ['বাংলা ভাষায় নির্ভুল নথি', 'مرحبا بالعالم', '😀 👍🏽', 'Wrapped row', 'VIII. Explicit item value']:
                assert canonical(expected) in text, f'CSS specimen lost text: {expected}'
            assert 'HIDDEN_TEXT' not in text, 'Invisible text leaked into extraction'
            assert canonical('This third line') not in text, 'Fully clipped text must not be emitted'
        raster = document[0].get_pixmap(colorspace=pymupdf.csRGB, alpha=False)
        raster.save(OUT / 'css-1.png')
        bitmap = pdfium[0].render(scale=1, rev_byteorder=True)
        other = pymupdf.Pixmap(pymupdf.csRGB, bitmap.width, bitmap.height, bytes(bitmap.buffer), False)
        pdfium_error = difference(raster, other)
        with tempfile.TemporaryDirectory() as directory:
            prefix = pathlib.Path(directory) / 'css'
            run([poppler, '-r', '72', '-png', '-singlefile', path, prefix])
            poppler_error = difference(raster, pymupdf.Pixmap(str(prefix) + '.png'))
        assert pdfium_error < .65 and poppler_error < .65, 'CSS rendering differs across engines'
        assert not pymupdf.TOOLS.mupdf_warnings(), 'PDF reader reported a problem in the CSS specimen'
    return dict(pages=1, embedded_fonts=fonts, pdfium_error=round(pdfium_error, 4), poppler_error=round(poppler_error, 4))


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    poppler = shutil.which('pdftoppm')
    extractor = os.environ.get('PDFTOTEXT') or shutil.which('pdftotext')
    assert poppler and extractor, 'Poppler pdftoppm and pdftotext are required'
    report = {'engines': {'mupdf': pymupdf.VersionBind, 'pdfium': str(pypdfium2.PDFIUM_INFO)}, 'documents': {}}
    for name, script in [('multilingual', 'specimen.php'), ('fonts', 'font_specimen.php')]:
        path = OUT / f'tinypdf-1.1.0-{name}.pdf'
        run(['php', ROOT / 'tests' / script, path])
        reader = PdfReader(path, strict=True)
        fonts = inspect_fonts(reader)
        mupdf = pymupdf.open(path)
        pdfium = pypdfium2.PdfDocument(path)
        assert len(mupdf) == len(pdfium) == 2
        mupdf_text = '\n'.join(page.get_text() for page in mupdf)
        pdfium_text = '\n'.join(page.get_textpage().get_text_bounded() for page in pdfium)
        poppler_text = run([extractor, '-enc', 'UTF-8', path, '-']).decode()
        if name == 'multilingual':
            assert TITLE in mupdf_text and TITLE in pdfium_text and TITLE in poppler_text
            assert EMOJI in mupdf_text and EMOJI in pdfium_text and EMOJI in poppler_text
            assert 'اردو زبان میں خوش آمدید' in mupdf_text and 'اردو زبان میں خوش آمدید' in pdfium_text
        document = {'pages': len(mupdf), 'embedded_fonts': fonts, 'comparisons': []}
        with tempfile.TemporaryDirectory() as directory:
            prefix = pathlib.Path(directory) / 'poppler'
            run([poppler, '-r', '72', '-png', path, prefix])
            for i, page in enumerate(mupdf):
                raster = page.get_pixmap(colorspace=pymupdf.csRGB, alpha=False)
                raster.save(OUT / f'{name}-{i + 1}.png')
                other = pymupdf.Pixmap(str(prefix) + f'-{i + 1}.png')
                poppler_error = difference(raster, other)
                bitmap = pdfium[i].render(scale=1, rev_byteorder=True)
                assert bitmap.n_channels == 3 and bitmap.stride == bitmap.width * 3
                rendered = pymupdf.Pixmap(pymupdf.csRGB, bitmap.width, bitmap.height, bytes(bitmap.buffer), False)
                pdfium_error = difference(raster, rendered)
                # Engines use different anti-aliasing. This detects lost/misplaced
                # text; the tighter approved MuPDF reference detects regressions.
                assert poppler_error < .65 and pdfium_error < .65, (name, i, poppler_error, pdfium_error)
                reference = REFERENCE / f'{name}-{i + 1}.png'
                assert reference.is_file(), f'Missing reviewed raster reference: {reference}'
                reference_error = difference(pymupdf.Pixmap(str(reference)), raster)
                assert reference_error < .08, f'Raster regression: {name} page {i + 1}: {reference_error:.3f}'
                document['comparisons'].append(dict(page=i + 1, poppler_error=round(poppler_error, 4),
                    pdfium_error=round(pdfium_error, 4), reference_error=round(reference_error, 4)))
        assert not pymupdf.TOOLS.mupdf_warnings(), 'MuPDF reported invalid PDF/font data'
        mupdf.close()
        pdfium.close()
        report['documents'][name] = document
    report['documents']['css'] = verify_css(poppler, extractor)
    report['extraction'] = verify_extraction(OUT / 'tinypdf-1.1.0-extraction.pdf')
    (OUT / 'rendering-results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
