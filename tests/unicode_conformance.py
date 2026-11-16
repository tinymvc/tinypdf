#!/usr/bin/env python3
"""Run the bundled ICU Unicode version against checksummed UCD conformance files."""
import hashlib,json,pathlib,sys
from reference import Helper, ROOT

def batches(helper,cases,check):
 pending=[]; count=0; failures=[]
 def flush():
  nonlocal count
  if not pending:return
  results=helper.call('batch',requests=[c[0] for c in pending])
  for (_,expected,label),result in zip(pending,results):
   count+=1
   reason=check(result,expected)
   if reason:failures.append((label,reason))
  pending.clear()
 for case in cases:
  pending.append(case)
  if len(pending)==256:flush()
 flush()
 return count,failures

def segmentation(path,kind):
 for line_number,line in enumerate(path.read_text().splitlines(),1):
  body=line.split('#')[0].strip()
  if not body:continue
  text='';positions=[];offset=0
  for token in body.split():
   if token=='÷':positions.append(offset)
   elif token!='×':
    c=chr(int(token,16));text+=c;offset+=len(c.encode('utf-16-le'))//2
  if any(0xd800<=ord(c)<=0xdfff for c in text):continue
  yield dict(op='analyze',text=text,language='root'),positions,f'{path.name}:{line_number}'

def bidi_characters(path):
 for line_number,line in enumerate(path.read_text().splitlines(),1):
  body=line.split('#')[0].strip()
  if not body:continue
  codepoints,base,paragraph,levels,order=body.split(';')[:5]
  text=''.join(chr(int(c,16)) for c in codepoints.split())
  yield dict(op='analyze',text=text,baseLevel=254 if base=='2' else int(base)),(int(paragraph),levels.split(),[int(x) for x in order.split()]),f'{path.name}:{line_number}'

def bidi_classes(path):
 representatives=dict(L='a',R='\u05d0',AL='\u0627',EN='1',ES='+',ET='$',AN='\u0661',CS=',',NSM='\u0300',BN='\u00ad',B='\u2029',S='\t',WS=' ',ON='!',LRE='\u202a',LRO='\u202d',RLE='\u202b',RLO='\u202e',PDF='\u202c',LRI='\u2066',RLI='\u2067',FSI='\u2068',PDI='\u2069')
 levels=[];order=[]
 for line_number,line in enumerate(path.read_text().splitlines(),1):
  body=line.split('#')[0].strip()
  if not body:continue
  if body.startswith('@Levels:'):levels=body.split(':')[1].split();continue
  if body.startswith('@Reorder:'):order=[int(x) for x in body.split(':')[1].split()];continue
  if body.startswith('@'):continue
  classes,bitset=body.split(';')
  text=''.join(representatives[c] for c in classes.split())
  for flag,base in [(1,254),(2,0),(4,1)]:
   if int(bitset,16)&flag:
    yield dict(op='analyze',text=text,baseLevel=base),(None,levels,order),f'{path.name}:{line_number}:{base}'

def check_bidi(result,expected):
 base,levels,order=expected
 if base is not None and result['baseLevel']!=base:return ['base',base,result['baseLevel']]
 chars=result['utf16ToCharacter'][:-1]
 actual=[]
 for index,char in enumerate(chars):
  if char==len(actual):actual.append(result['levels'][index])
 if any(value!='x' and int(value)!=actual[i] for i,value in enumerate(levels)):return ['levels',levels,actual]
 visual=[]
 for index in result['order']:
  char=chars[index]
  if levels[char]!='x' and char not in visual:visual.append(char)
 if visual!=order:return ['order',order,visual]

def main():
 manifest=json.loads((ROOT/'tests/unicode/manifest.json').read_text())
 for f in manifest['files']:
  path=ROOT/f['file'];assert path.is_file(),f'Missing required Unicode fixture: {path}'
  assert hashlib.sha256(path.read_bytes()).hexdigest()==f['sha256'],f'Checksum mismatch: {path}'
 helper=Helper();report={}
 try:
  version=helper.call('capabilities')['unicode']
  assert version.startswith('17.'),'This conformance pack requires Unicode 17; found '+version
  for filename,field in [('GraphemeBreakTest.txt','graphemes'),('LineBreakTest.txt','breaks')]:
   total,failed=batches(helper,segmentation(ROOT/'tests/unicode'/filename,field),lambda result,expected: None if [x for x in result[field] if x!=0]==[x for x in expected if x!=0] else [expected,result[field]])
   print(filename,total,'cases,',len(failed),'failures',flush=True);report[filename]=dict(total=total,failures=failed)
  for filename,cases in [('BidiCharacterTest.txt',bidi_characters),('BidiTest.txt',bidi_classes)]:
   total,failed=batches(helper,cases(ROOT/'tests/unicode'/filename),check_bidi)
   print(filename,total,'cases,',len(failed),'failures',flush=True);report[filename]=dict(total=total,failures=failed)
 finally:helper.close()
 (ROOT/'tests/unicode/results.json').write_text(json.dumps(report,indent=2)+'\n')
 if any(v['failures'] for v in report.values()):raise SystemExit(1)
if __name__=='__main__':main()
