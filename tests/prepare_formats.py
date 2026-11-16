#!/usr/bin/env python3
"""Build deterministic container/outline fixtures from the pinned OFL Latin font."""
from pathlib import Path
from fontTools.ttLib import TTFont,TTCollection
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.fontBuilder import FontBuilder
from fontTools.pens.t2CharStringPen import T2CharStringPen
from fontTools.pens.qu2cuPen import Qu2CuPen
from fontTools.subset import Subsetter,Options
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tests/fonts/formats';OUT.mkdir(parents=True,exist_ok=True)
source=next(p for p in (ROOT/'tests/fonts/notosans').glob('*.ttf') if 'Italic' not in p.name)
for flavor in ['woff','woff2']:
 font=TTFont(source);font.flavor=flavor;font.save(OUT/f'Latin.{flavor}')
collection=TTCollection();collection.fonts=[instantiateVariableFont(TTFont(source),dict(wght=100,wdth=100)),instantiateVariableFont(TTFont(source),dict(wght=900,wdth=100))];collection.save(OUT/'Latin.ttc')
font=instantiateVariableFont(TTFont(source),dict(wght=400,wdth=100))
subset=Subsetter(options=Options());subset.populate(text='Cubic office café CFF2 0123456789');subset.subset(font)
glyphs=font.getGlyphSet();order=font.getGlyphOrder();charstrings={}
for name in order:
 pen=T2CharStringPen(glyphs[name].width,glyphs)
 glyphs[name].draw(Qu2CuPen(pen,max_err=0.5,all_cubic=True))
 charstrings[name]=pen.getCharString()
builder=FontBuilder(font['head'].unitsPerEm,isTTF=False)
builder.setupGlyphOrder(order);builder.setupCharacterMap(font.getBestCmap())
builder.setupCFF('TinyPDFCubic',{'FullName':'TinyPDF Cubic','FamilyName':'TinyPDF Cubic','Weight':'Regular'},charstrings,{})
builder.setupHorizontalMetrics({name:font['hmtx'][name] for name in order})
builder.setupHorizontalHeader(ascent=font['hhea'].ascent,descent=font['hhea'].descent)
builder.setupNameTable({'familyName':'TinyPDF Cubic','styleName':'Regular','uniqueFontIdentifier':'TinyPDF-1.1.0-Cubic','fullName':'TinyPDF Cubic','psName':'TinyPDFCubic'})
builder.setupOS2(sTypoAscender=font['OS/2'].sTypoAscender,sTypoDescender=font['OS/2'].sTypoDescender,usWinAscent=font['OS/2'].usWinAscent,usWinDescent=font['OS/2'].usWinDescent)
builder.setupPost();builder.save(OUT/'Latin.otf')
from fontTools.cffLib.CFFToCFF2 import convertCFFToCFF2
convertCFFToCFF2(builder.font);builder.save(OUT/'Latin-CFF2.otf')
print('Prepared WOFF, WOFF2, TTC face 0/1, CFF and CFF2 fixtures.')
