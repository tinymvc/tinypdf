#!/usr/bin/env python3
"""Compare the helper's glyphs against the independent hb-shape CLI with pinned fonts."""
import json, pathlib, struct, subprocess, os, sys, hashlib
ROOT = pathlib.Path(__file__).resolve().parents[1]
CASES = [
 ('notosansbengali','bn','Beng','ltr','বাংলা ভাষায় নির্ভুল নথি তৈরি প্র ক্ত র্ক ক্ষি'),
 ('notosansbengali','bn','Beng','ltr','ক্\u200dষ ক্\u200cষ র্কি কো কৌ'),
 ('notosansdevanagari','hi','Deva','ltr','हिन्दी क्ष त्र ज्ञ कि कर्म श्री'),
 ('notosanstamil','ta','Taml','ltr','தமிழ் மொழி அனைவருக்கும் வணக்கம்'),
 ('notosanstelugu','te','Telu','ltr','తెలుగు భాష ప్రపంచానికి నమస్కారం'),
 ('notosansmalayalam','ml','Mlym','ltr','മലയാളം ഭാഷ എല്ലാവർക്കും നമസ്കാരം'),
 ('notosanskannada','kn','Knda','ltr','ಕನ್ನಡ ಭಾಷೆ ಎಲ್ಲರಿಗೂ ನಮಸ್ಕಾರ'),
 ('notosansgujarati','gu','Gujr','ltr','ગુજરાતી ભાષા વિશ્વને નમસ્કાર'),
 ('notosansgurmukhi','pa','Guru','ltr','ਪੰਜਾਬੀ ਭਾਸ਼ਾ ਸਭ ਨੂੰ ਸਤਿ ਸ੍ਰੀ ਅਕਾਲ'),
 ('notosanssinhala','si','Sinh','ltr','සිංහල භාෂාව ලෝකයට ආයුබෝවන්'),
 ('notosansoriya','or','Orya','ltr','ଓଡ଼ିଆ ଭାଷା ସମସ୍ତଙ୍କୁ ନମସ୍କାର'),
 ('notonaskharabic','ar','Arab','rtl','مَرْحَبًا بِالْعَالَمِ'),
 ('notonaskharabic','fa','Arab','rtl','فارسی زبان زیبایی است'),
 ('notonastaliqurdu','ur','Arab','rtl','اردو زبان میں خوش آمدید'),
 ('notosanshebrew','he','Hebr','rtl','שָׁלוֹם עוֹלָם'),
 ('notosansthai','th','Thai','ltr','ภาษาไทย ยินดีต้อนรับ สวัสดีชาวโลก'),
 ('notosanslao','lo','Laoo','ltr','ພາສາລາວ ສະບາຍດີ'),
 ('notosanskhmer','km','Khmr','ltr','ភាសាខ្មែរ សួស្តីពិភពលោក'),
 ('notosansmyanmar','my','Mymr','ltr','မြန်မာဘာသာ မင်္ဂလာပါ'),
 ('notoseriftibetan','bo','Tibt','ltr','བོད་ཡིག བཀྲ་ཤིས་བདེ་ལེགས།'),
 ('notosanssc','zh-Hans','Hani','ltr','简体中文：你好，世界！'),
 ('notosanstc','zh-Hant','Hani','ltr','繁體中文：你好，世界！'),
 ('notosansjp','ja','Hani','ltr','日本語漢字𠀋𠮷'),
 ('notosansjp','ja','Hira','ltr','こんにちは、せかい。'),
 ('notosanskr','ko','Hang','ltr','한국어 안녕하세요 세계 한'),
 ('notosans','fr','Latn','ltr','Français café œuvre office e\u0301'),
 ('notosans','vi','Latn','ltr','Tiếng Việt: tiếng nói a\u0306\u0301'),
 ('notosans','el','Grek','ltr','Ελληνικά Καλημέρα κόσμε'),
 ('notosans','ru','Cyrl','ltr','Русский Привет мир'),
 ('notocoloremoji','und','Zyyy','ltr','😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽 ❤️'),
 ('notosansjp','ja','Hani','ttb','日本語漢字「縦書」'),
]
class Helper:
 def __init__(self):
  executable=os.environ.get('TINYPDF_TEXT_HELPER',str(ROOT/'native/build'/('tinypdf-text.exe' if os.name=='nt' else 'tinypdf-text')))
  self.p=subprocess.Popen([executable],stdin=subprocess.PIPE,stdout=subprocess.PIPE)
  self.id=0
 def call(self,op,**args):
  self.id+=1
  data=json.dumps(dict(protocol=1,id=self.id,op=op,**args),ensure_ascii=False).encode()
  self.p.stdin.write(struct.pack('!I',len(data))+data);self.p.stdin.flush()
  header=self.p.stdout.read(4)
  assert len(header)==4,'helper exited'
  response=json.loads(self.p.stdout.read(struct.unpack('!I',header)[0]))
  assert response['id']==self.id
  if 'error' in response:raise RuntimeError(response['error'])
  return response['result']
 def close(self):
  self.p.stdin.close();self.p.wait(timeout=5)

def font_path(pack):
 entries=[p for p in (ROOT/'tests/fonts'/pack).glob('*.ttf') if 'Italic' not in p.name]
 assert len(entries)==1,f'Missing pinned font pack: {pack}; run php bin/install-fonts.php'
 return entries[0]

def verify_fonts():
 manifest=json.loads((ROOT/'assets/font-packs.json').read_text())
 for entry in manifest['files']:
  path=ROOT/entry['file']
  assert path.is_file(),f'Missing required fixture {path}'
  assert hashlib.sha256(path.read_bytes()).hexdigest()==entry['sha256'],f'Checksum mismatch: {path}'

def main():
 verify_fonts()
 helper=Helper();failures=[]
 try:
  for pack,language,script,direction,text in CASES:
   path=font_path(pack)
   font=helper.call('font',path=str(path))
   upem=font['upem']
   style=dict(fonts=[font['font']],size=upem,language=language,orientation='upright')
   output=helper.call('layout',text=text,width=1e9,direction='rtl' if direction=='rtl' else 'ltr',vertical=direction=='ttb',spans=[dict(start=0,end=len(text.encode()),item=0,style=style)])
   actual=[]
   for line in output['lines']:
    for run in line['items']:
     actual.extend(dict(g=g['gid'],cl=g['cluster'],ax=g['ax'],ay=g['ay'],dx=g['dx'],dy=g['dy']) for g in run['glyphs'])
   reference=json.loads(subprocess.check_output(['hb-shape',str(path),text,'--output-format=json','--no-glyph-names','--utf8-clusters','--cluster-level=0','--shapers=ot','--font-funcs=ot','--font-size=upem','--bot','--eot','--direction='+direction,'--script='+script,'--language='+language]))
   reference=[{k:g[k] for k in ('g','cl','ax','ay','dx','dy')} for g in reference]
   if actual!=reference:
    failures.append((language,text,actual,reference))
    print('FAIL',language,repr(text))
   else:print('PASS',language,direction)
 finally:helper.close()
 if failures:
  (ROOT/'tests/reference-failures.json').write_text(json.dumps(failures,ensure_ascii=False,indent=2))
  raise SystemExit(f'{len(failures)} reference mismatches')
 print(f'{len(CASES)} HarfBuzz references matched: glyph IDs, byte clusters, advances and offsets.')
if __name__=='__main__':main()
