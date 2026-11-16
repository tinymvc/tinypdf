#!/usr/bin/env python3
"""Bundle a tested helper with its non-system shared libraries and a checksum inventory."""
import argparse,hashlib,json,os,pathlib,platform,re,shutil,subprocess,tarfile,urllib.request
ROOT=pathlib.Path(__file__).resolve().parent
parser=argparse.ArgumentParser();parser.add_argument('--binary',type=pathlib.Path,default=ROOT/'build'/('tinypdf-text.exe' if os.name=='nt' else 'tinypdf-text'));parser.add_argument('--output',type=pathlib.Path,default=ROOT.parent/'dist');args=parser.parse_args()
system=platform.system().lower();arch={'aarch64':'arm64','x86_64':'x64','amd64':'x64'}.get(platform.machine().lower(),platform.machine().lower())
name=f'tinypdf-text-1.1.0-{system}-{arch}'
folder=args.output/name
if folder.exists():raise SystemExit(f'Package already exists: {folder}')
(folder/'bin').mkdir(parents=True);(folder/'lib').mkdir();(folder/'licenses').mkdir()
helper=folder/'bin'/(args.binary.name+'.bin' if system=='linux' else args.binary.name);shutil.copy2(args.binary,helper)
def output(command):return subprocess.check_output(command,text=True)
def dependencies(path):
 if system=='darwin':
  return [line.strip().split(' (')[0] for line in output(['otool','-L',str(path)]).splitlines()[1:] if not line.strip().startswith(('/usr/lib/','/System/'))]
 if system=='linux':
  if path.name.startswith('ld-linux'):return []
  linked=output(['ldd',str(path)])
  return re.findall(r'=> (/\S+)',linked)+re.findall(r'^\s*(/\S+)\s+\(',linked,re.MULTILINE)
 names=re.findall(r'DLL Name:\s*(\S+)',output(['objdump','-p',str(path)]))
 found=[]
 for dll in names:
  if dll.lower().startswith(('api-ms-','ext-ms-')):continue
  resolved=shutil.which(dll) or next((str(pathlib.Path(p)/dll) for p in os.environ['PATH'].split(os.pathsep) if (pathlib.Path(p)/dll).is_file()),None)
  if resolved and 'windows/system32' not in resolved.replace('\\','/').lower():found.append(resolved)
 return found
system_libraries={}
library_owners={}
package_roots={}
def package_owned_library(path):
 resolved=path.resolve()
 if resolved.is_relative_to(ROOT/'prefix'):return path
 if str(resolved) in system_libraries:return system_libraries[str(resolved)]
 query=subprocess.run(['dpkg-query','-S',str(resolved)],text=True,capture_output=True)
 if re.search(r'^[a-z0-9][a-z0-9+.-]*(?::[a-z0-9]+)?: ',query.stdout,re.MULTILINE):return path
 dynamic=output(['readelf','-d',str(path)])
 match=re.search(r'\(SONAME\).*?\[([^]]+)\]',dynamic)
 soname=match[1] if match else path.name
 query=subprocess.run(['dpkg-query','-S','*/'+soname],text=True,capture_output=True)
 packages=[]
 for line in query.stdout.splitlines():
  owner=re.match(r'^([a-z0-9][a-z0-9+.-]*(?::[a-z0-9]+)?): ',line)
  if owner:packages.append(owner[1])
 for package in packages:
  listed=output(['dpkg-query','-L',package]).splitlines()
  candidates=[pathlib.Path(name) for name in listed if pathlib.Path(name).name.startswith(soname+'.') or pathlib.Path(name).name==soname]
  available=next((candidate for candidate in candidates if candidate.is_file() and not candidate.is_symlink()),None)
  if available is None:
   # Some PHP setup tools replace system shared objects outside dpkg. Recover
   # the distribution's matching runtime instead of shipping unowned binaries.
   staging=ROOT/'build/system-libraries'/package.replace(':','-');staging.mkdir(parents=True,exist_ok=True)
   version=output(['dpkg-query','-W','-f=${Version}',package]).strip()
   subprocess.run(['apt-get','download',package+'='+version],cwd=staging,check=True)
   archive=next(staging.glob('*.deb'));extracted=staging/'root'
   subprocess.run(['dpkg-deb','-x',str(archive),str(extracted)],check=True)
   available=next((extracted/str(candidate).lstrip('/') for candidate in candidates
                   if (extracted/str(candidate).lstrip('/')).is_file() and not (extracted/str(candidate).lstrip('/')).is_symlink()),None)
   package_roots[package]=extracted
  if available:
   print('Using package-owned runtime:',available,flush=True)
   system_libraries[str(resolved)]=available
   library_owners[str(available.resolve())]=package
   return available
 raise SystemExit(f'Cannot identify a distribution-owned runtime for {path} (resolved to {resolved})')
baseline={'libc.so.6','libm.so.6','libpthread.so.0','libdl.so.2','librt.so.1','libresolv.so.2','libutil.so.1'}
queue=[(args.binary.resolve(),helper)];copied={};edges=[];sources={}
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
  if path.resolve()==original.resolve():continue
  if not path.is_file():raise SystemExit(f'Unresolved dependency: {dependency}')
  requested_name=path.name
  if system=='linux':path=package_owned_library(path)
  source=str(path.resolve())
  filename=sources.get(source,requested_name)
  if filename in copied and copied[filename]!=source:
   if system!='darwin':raise SystemExit(f'Conflicting runtime libraries: {filename}')
   filename=hashlib.sha256(source.encode()).hexdigest()[:12]+'-'+path.name
  target=(folder/'bin' if system=='windows' else folder/'lib')/filename
  edges.append((destination,reference,target))
  if source not in sources:
   sources[source]=filename;copied[filename]=source
   shutil.copy2(path,target);target.chmod(0o755);queue.append((path.resolve(),target))
for destination,dependency,target in edges:
 if system=='darwin':subprocess.run(['install_name_tool','-change',dependency,'@loader_path/'+os.path.relpath(target,destination.parent),str(destination)],check=True)
for binary in [helper,*[(folder/'bin' if system=='windows' else folder/'lib')/name for name in copied]]:
 if system=='darwin':
  if binary!=helper:subprocess.run(['install_name_tool','-id','@loader_path/'+binary.name,str(binary)],check=True)
  subprocess.run(['codesign','--force','--sign','-',str(binary)],check=True,stdout=subprocess.DEVNULL)
 elif system=='linux' and binary.name not in baseline and not binary.name.startswith('ld-linux'):
  subprocess.run(['patchelf','--set-rpath','$ORIGIN/../lib' if binary==helper else '$ORIGIN',str(binary)],check=True)
if system=='linux':
 loaders=[name for name in copied if name.startswith('ld-linux')]
 if len(loaders)!=1:raise SystemExit('Expected one private Linux dynamic loader')
 launcher=folder/'bin'/'tinypdf-text'
 launcher.write_text('#!/bin/sh\ndirectory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P) || exit 1\n'
  +f'exec "$directory/../lib/{loaders[0]}" --inhibit-cache --library-path "$directory/../lib" "$directory/{helper.name}" "$@"\n')
 launcher.chmod(0o755)
# Preserve legal notices for every bundled library, including transitive ones.
license_names={'copying','copying.lib','license','license.txt','license.html','ftl.txt','copyright'}
license_inventory={}
source_inventory={}
formula_notices={}
def is_notice(path):
 return path.name.lower() in license_names or path.name.upper().startswith(('LICENSE','COPYING','COPYRIGHT','NOTICE','AUTHORS')) or 'licenses' in [part.lower() for part in path.parts]
def copy_notices(directory, package):
 notices=[]
 if directory.is_dir():
  for document in directory.rglob('*'):
   if document.is_file() and is_notice(document.relative_to(directory)):
    target=folder/'licenses'/package/document.relative_to(directory)
    target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(document,target)
    notices.append(str(target.relative_to(folder)))
 return notices
def homebrew_source_notices(cellar):
 package=cellar.parent.name
 if package in formula_notices:return formula_notices[package]
 info=json.loads(output(['brew','info','--json=v2',package]))['formulae'][0]
 version=info['versions']['stable']
 if cellar.name.split('_')[0]!=version:raise SystemExit(f'Cannot identify installed source version for {cellar}')
 source=info['urls']['stable']
 checksum=source['checksum']
 archive=ROOT/'downloads'/(checksum+'-'+pathlib.Path(source['url']).name)
 archive.parent.mkdir(exist_ok=True)
 if not archive.is_file():
  with urllib.request.urlopen(source['url'],timeout=120) as response:archive.write_bytes(response.read())
 if hashlib.sha256(archive.read_bytes()).hexdigest()!=checksum:raise SystemExit(f'Source checksum mismatch: {package}')
 notices=[]
 with tarfile.open(archive) as contents:
  for member in contents:
   relative=pathlib.PurePosixPath(member.name)
   if not member.isfile() or relative.is_absolute() or '..' in relative.parts or not is_notice(relative):continue
   target=folder/'licenses'/package/pathlib.Path(*relative.parts[1:])
   target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(contents.extractfile(member).read())
   notices.append(str(target.relative_to(folder)))
 source_inventory[package]=dict(version=version,url=source['url'],sha256=checksum,homepage=info['homepage'])
 formula_notices[package]=notices
 return notices
pinned_notices=copy_notices(ROOT/'prefix/share/licenses','pinned')
for filename,library in copied.items():
 path=pathlib.Path(library)
 notices=[]
 if path.is_relative_to(ROOT/'prefix'):
  notices=pinned_notices
 elif system=='darwin':
  # Homebrew stores notices beside each installed Cellar version.
  cellar=next((parent for parent in path.parents if parent.parent.parent.name=='Cellar'),None)
  if cellar:
   notices=copy_notices(cellar,cellar.parent.name)
   if not notices:notices=homebrew_source_notices(cellar)
  elif str(path).startswith('/opt/local/'):
   result=subprocess.run(['port','provides',library],text=True,capture_output=True)
   match=re.search(r'provided by: (\S+)',result.stdout)
   if match:notices=copy_notices(pathlib.Path('/opt/local/share/doc')/match[1],match[1])
 elif system=='linux':
  if library in library_owners:
   packages=[library_owners[library]]
  else:
   result=subprocess.run(['dpkg-query','-S',library],text=True,capture_output=True)
   if result.returncode:result=subprocess.run(['dpkg-query','-S','*/'+path.name],text=True,capture_output=True)
   packages=[line.split(': ')[0] for line in result.stdout.splitlines()]
  for package in packages:
   root=package_roots.get(package,pathlib.Path('/'))
   name=package.split(':')[0]
   found=copy_notices(root/'usr/share/doc'/name,name)
   if not found and root!=pathlib.Path('/'):found=copy_notices(pathlib.Path('/usr/share/doc')/name,name)
   notices+=found
 elif system=='windows':
  unix_path=output(['cygpath','-u',library]).strip()
  package=output(['pacman','-Qqo',unix_path]).strip()
  for line in output(['pacman','-Ql',package]).splitlines():
   installed=line.split(' ',1)[-1]
   if '/share/licenses/' not in installed or installed.endswith('/'):continue
   document=pathlib.Path(output(['cygpath','-w',installed]).strip())
   if document.is_file():
    relative=installed.split('/share/licenses/',1)[1]
    target=folder/'licenses'/relative;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(document,target)
    notices.append(str(target.relative_to(folder)))
 if not notices:raise SystemExit(f'Missing redistribution notices for {library}')
 license_inventory[filename]=sorted(set(notices))
if system=='linux':
 # Debian copyright notices reference these complete license texts.
 common=pathlib.Path('/usr/share/common-licenses')
 if common.is_dir():shutil.copytree(common,folder/'licenses/common-licenses',dirs_exist_ok=True)
shutil.copy2(ROOT.parent/'LICENSE',folder/'licenses/TinyPDF-LICENSE')
shutil.copy2(ROOT/'vendor/json.LICENSE',folder/'licenses/json.LICENSE')
shutil.copy2(ROOT/'sources.lock.json',folder/'sources.lock.json')
shutil.copy2(ROOT/'THIRD_PARTY.md',folder/'THIRD_PARTY.md')
versions={}
for dependency in ['harfbuzz','icu-uc','fribidi','freetype2','libbrotlidec','cairo','librsvg-2.0','libpng']:
 versions[dependency]=output(['pkg-config','--modversion',dependency]).strip()
if system=='linux':versions['glibc']=platform.libc_ver()[1]
files={str(p.relative_to(folder)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(folder.rglob('*')) if p.is_file()}
(folder/'manifest.json').write_text(json.dumps(dict(version='1.1.0',platform=system,architecture=arch,dependencies=versions,library_licenses=license_inventory,notice_sources=source_inventory,files=files),indent=2)+'\n')
archive=shutil.make_archive(str(args.output/name),'zip' if system=='windows' else 'gztar',root_dir=args.output,base_dir=name)
path=pathlib.Path(archive);path.with_suffix(path.suffix+'.sha256').write_text(hashlib.sha256(path.read_bytes()).hexdigest()+'  '+path.name+'\n')
print(path)
