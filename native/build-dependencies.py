#!/usr/bin/env python3
"""Build the pinned text engines in native/prefix; system Cairo/FreeType/RSVG stay dynamic."""
import hashlib,json,os,pathlib,shutil,subprocess,tarfile,urllib.request
ROOT=pathlib.Path(__file__).resolve().parent
PREFIX=ROOT/'prefix'
DOWNLOADS=ROOT/'downloads'
SOURCES=ROOT/'build/dependencies'
for directory in [PREFIX,DOWNLOADS,SOURCES]:directory.mkdir(parents=True,exist_ok=True)
lock=json.loads((ROOT/'sources.lock.json').read_text())
env=os.environ.copy()
env['PATH']=str(PREFIX/'bin')+os.pathsep+env.get('PATH','')
env['PKG_CONFIG_PATH']=str(PREFIX/'lib/pkgconfig')+os.pathsep+str(PREFIX/'share/pkgconfig')+os.pathsep+env.get('PKG_CONFIG_PATH','')
env['LD_LIBRARY_PATH']=str(PREFIX/'lib')+os.pathsep+env.get('LD_LIBRARY_PATH','')
def run(args,cwd):subprocess.run([str(x) for x in args],cwd=cwd,env=env,check=True)
for name in ['icu','fribidi','harfbuzz']:
 entry=next(x for x in lock['sources'] if x['name']==name)
 archive=DOWNLOADS/entry['file']
 if not archive.exists():
  request=urllib.request.Request(entry['url'],headers={'User-Agent':'TinyPDF-1.1.0-build'})
  with urllib.request.urlopen(request,timeout=120) as response:archive.write_bytes(response.read())
 if hashlib.sha256(archive.read_bytes()).hexdigest()!=entry['sha256']:raise SystemExit('Source checksum mismatch: '+name)
 folder=SOURCES/name
 if not folder.exists():
  folder.mkdir()
  with tarfile.open(archive) as tar:tar.extractall(folder,filter='data')
 source=next(x for x in folder.iterdir() if x.is_dir() and x.name!='build')
 if name=='icu':
  source=source/'source'
  run(['sh','configure','--prefix='+str(PREFIX),'--libdir='+str(PREFIX/'lib'),'--enable-rpath','--disable-tests','--disable-samples','--with-data-packaging=library'],source)
  run(['make','-j',str(min(os.cpu_count() or 2,8))],source)
  run(['make','install'],source)
 else:
  build=folder/'build'
  args=['meson','setup',str(build),str(source),'--prefix='+str(PREFIX),'--libdir=lib','--buildtype=release','-Ddefault_library=shared']
  if build.exists():args+=['--reconfigure']
  if name=='harfbuzz':args+=['-Dtests=disabled','-Ddocs=disabled','-Dutilities=enabled','-Dcairo=enabled','-Dfreetype=enabled','-Dpng=enabled','-Dzlib=enabled','-Dglib=enabled','-Dgobject=enabled','-Dicu=disabled','-Dgraphite2=disabled','-Dcoretext=auto','-Dgdi=auto','-Ddirectwrite=auto','-Draster=disabled','-Dvector=disabled','-Dgpu=disabled']
  else:args+=['-Ddocs=false','-Dtests=false']
  run(args,ROOT);run(['meson','compile','-C',build],ROOT);run(['meson','install','-C',build],ROOT)
 licenses=PREFIX/'share/licenses'/name;licenses.mkdir(parents=True,exist_ok=True)
 for candidate in [source/'COPYING',source/'LICENSE',source.parent/'LICENSE']:
  if candidate.is_file():shutil.copy2(candidate,licenses/candidate.name)
print('Build environment:')
print('PKG_CONFIG_PATH='+env['PKG_CONFIG_PATH'])
print('PATH='+env['PATH'])
