#!/usr/bin/env python3
"""Exercise three PDF engines, embedded fonts and reviewed raster fixtures.

Reader extraction discrepancies are recorded explicitly, not treated as proof
that the generated source mappings are missing or that every reader is certified.
"""
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
from pypdf.generic import ContentStream

ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = ROOT / 'output/pdf'
REFERENCE = ROOT / 'tests/reference'
TITLE = 'বাংলা ভাষায় নির্ভুল নথি তৈরি'
EMOJI = '😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽 ❤️'


def run(args):
    result = subprocess.run([str(x) for x in args], cwd=ROOT, capture_output=True, check=True)
    assert not result.stderr, result.stderr.decode(errors='replace')
    return result.stdout


def logical_text(reader):
    lines = []
    for page in reader.pages:
        depth = 0
        for operands, operator in ContentStream(page.get_contents(), reader).operations:
            if operator == b'q':
                depth += 1
            elif operator == b'Q':
                depth -= 1
                assert depth >= 0, 'Unbalanced graphics state'
            elif operator == b'BDC' and '/ActualText' in operands[-1]:
                lines.append(str(operands[-1]['/ActualText']))
        assert depth == 0, 'Unbalanced graphics state'
    return '\n'.join(lines)


def inspect_fonts(reader):
    checked = set()
    for page in reader.pages:
        for reference in page['/Resources']['/Font'].values():
            if reference.idnum in checked:
                continue
            checked.add(reference.idnum)
            font = reference.get_object()
            assert '/ToUnicode' in font
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
        logical = logical_text(reader)
        fonts = inspect_fonts(reader)
        mupdf = pymupdf.open(path)
        pdfium = pypdfium2.PdfDocument(path)
        assert len(mupdf) == len(pdfium) == 2
        mupdf_text = '\n'.join(page.get_text() for page in mupdf)
        pdfium_text = '\n'.join(page.get_textpage().get_text_bounded() for page in pdfium)
        poppler_text = run([extractor, '-enc', 'UTF-8', path, '-']).decode()
        if name == 'multilingual':
            assert TITLE in logical
            assert TITLE in mupdf_text and TITLE in pdfium_text and TITLE in poppler_text
            assert EMOJI in logical and EMOJI in mupdf_text
            assert 'اردو زبان میں خوش آمدید' in pdfium_text
        document = {'pages': len(mupdf), 'embedded_fonts': fonts, 'comparisons': []}
        if name == 'multilingual':
            document['reader_observations'] = {
                'pdfium_exact_emoji_sequence': EMOJI in pdfium_text,
                'mupdf_exact_urdu_sequence': 'اردو زبان میں خوش آمدید' in mupdf_text,
            }
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
    (OUT / 'rendering-results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
