#!/usr/bin/env python3
"""Bundle a tested helper with its non-system shared libraries and a checksum inventory."""
import argparse,hashlib,json,os,pathlib,platform,re,shutil,subprocess
ROOT=pathlib.Path(__file__).resolve().parent
parser=argparse.ArgumentParser();parser.add_argument('--binary',type=pathlib.Path,default=ROOT/'build'/('tinypdf-text.exe' if os.name=='nt' else 'tinypdf-text'));parser.add_argument('--output',type=pathlib.Path,default=ROOT.parent/'dist');args=parser.parse_args()
system=platform.system().lower();arch={'aarch64':'arm64','x86_64':'x64','amd64':'x64'}.get(platform.machine().lower(),platform.machine().lower())
name=f'tinypdf-text-1.1.0-{system}-{arch}'
folder=args.output/name
if folder.exists():raise SystemExit(f'Package already exists: {folder}')
(folder/'bin').mkdir(parents=True);(folder/'lib').mkdir();(folder/'licenses').mkdir()
helper=folder/'bin'/args.binary.name;shutil.copy2(args.binary,helper)
def output(command):return subprocess.check_output(command,text=True)
def dependencies(path):
 if system=='darwin':
  return [line.strip().split(' (')[0] for line in output(['otool','-L',str(path)]).splitlines()[1:] if not line.strip().startswith(('/usr/lib/','/System/'))]
 if system=='linux':
  return re.findall(r'=> (/\S+)',output(['ldd',str(path)]))
 names=re.findall(r'DLL Name:\s*(\S+)',output(['objdump','-p',str(path)]))
 found=[]
 for dll in names:
  if dll.lower().startswith(('api-ms-','ext-ms-')):continue
  resolved=shutil.which(dll) or next((str(pathlib.Path(p)/dll) for p in os.environ['PATH'].split(os.pathsep) if (pathlib.Path(p)/dll).is_file()),None)
  if resolved and 'windows/system32' not in resolved.replace('\\','/').lower():found.append(resolved)
 return found
baseline={'libc.so.6','libm.so.6','libpthread.so.0','libdl.so.2','librt.so.1','libresolv.so.2','libutil.so.1'}
queue=[(args.binary.resolve(),helper)];copied={};edges=[]
while queue:
 original,destination=queue.pop(0)
 for dependency in dependencies(original):
  reference=dependency
  if dependency.startswith('@loader_path/'):
   dependency=str(original.parent/dependency[len('@loader_path/'):])
  if dependency.startswith('@rpath/'):
   candidates=[original.parent/dependency[7:],ROOT/'prefix/lib'/dependency[7:]]
   dependency=str(next((p for p in candidates if p.is_file()),pathlib.Path(dependency)))
  path=pathlib.Path(dependency)
  if path.name in baseline or path.name.startswith('ld-linux'):continue
  if path.resolve()==original.resolve():continue
  if not path.is_file():raise SystemExit(f'Unresolved dependency: {dependency}')
  target=(folder/'bin' if system=='windows' else folder/'lib')/path.name
  edges.append((destination,reference,target))
  if path.name not in copied:
   copied[path.name]=str(path.resolve());shutil.copy2(path,target);target.chmod(0o755);queue.append((path.resolve(),target))
for destination,dependency,target in edges:
 if system=='darwin':subprocess.run(['install_name_tool','-change',dependency,'@loader_path/'+os.path.relpath(target,destination.parent),str(destination)],check=True)
for binary in [helper,*[(folder/'bin' if system=='windows' else folder/'lib')/name for name in copied]]:
 if system=='darwin':
  if binary!=helper:subprocess.run(['install_name_tool','-id','@loader_path/'+binary.name,str(binary)],check=True)
  subprocess.run(['codesign','--force','--sign','-',str(binary)],check=True,stdout=subprocess.DEVNULL)
 elif system=='linux':subprocess.run(['patchelf','--set-rpath','$ORIGIN/../lib' if binary==helper else '$ORIGIN',str(binary)],check=True)
# Preserve license documents supplied by the native dependency installation and OS package manager.
for prefix in [ROOT/'prefix/share/licenses',pathlib.Path('/opt/local/share/doc'),pathlib.Path('/opt/homebrew/share/licenses'),pathlib.Path('/usr/share/licenses')]:
 if prefix.is_dir():
  for path in prefix.rglob('*'):
   if path.is_file() and path.name.lower() in {'copying','copying.lib','license','license.txt','license.html','ftl.txt','copyright'}:
    if prefix==ROOT/'prefix/share/licenses' or any(x in str(path).lower() for x in ['cairo','freetype','harfbuzz','icu','fribidi','librsvg','libpng','pixman','glib','pcre','ffi','gettext','brotli','fontconfig','iconv','expat','zlib']):
     target=folder/'licenses'/path.relative_to(prefix);target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(path,target)
if system=='linux':
 for library in copied.values():
  result=subprocess.run(['dpkg-query','-S',library],text=True,capture_output=True)
  for line in result.stdout.splitlines():
   package=line.split(': ')[0].split(':')[0];license=pathlib.Path('/usr/share/doc')/package/'copyright'
   if license.is_file():shutil.copy2(license,folder/'licenses'/(package+'.copyright'))
shutil.copy2(ROOT.parent/'LICENSE',folder/'licenses/TinyPDF-LICENSE')
shutil.copy2(ROOT/'vendor/json.LICENSE',folder/'licenses/json.LICENSE')
shutil.copy2(ROOT/'sources.lock.json',folder/'sources.lock.json')
shutil.copy2(ROOT/'THIRD_PARTY.md',folder/'THIRD_PARTY.md')
versions={}
for dependency in ['harfbuzz','icu-uc','fribidi','freetype2','cairo','librsvg-2.0','libpng']:
 versions[dependency]=output(['pkg-config','--modversion',dependency]).strip()
files={str(p.relative_to(folder)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(folder.rglob('*')) if p.is_file()}
(folder/'manifest.json').write_text(json.dumps(dict(version='1.1.0',platform=system,architecture=arch,dependencies=versions,files=files),indent=2)+'\n')
archive=shutil.make_archive(str(args.output/name),'zip' if system=='windows' else 'gztar',root_dir=args.output,base_dir=name)
path=pathlib.Path(archive);path.with_suffix(path.suffix+'.sha256').write_text(hashlib.sha256(path.read_bytes()).hexdigest()+'  '+path.name+'\n')
print(path)
