import hashlib,importlib.util,json,shutil,subprocess,sys,tarfile,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('package',root/'scripts/build_firstvds_release.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix='firstvds-package-') as tmp:
    tmp=Path(tmp);archive=tmp/'release.tar.gz'
    subprocess.run([sys.executable,str(root/'scripts/build_firstvds_release.py'),'build','--output',str(archive)],check=True)
    bundle=tmp/'bundle';bundle.mkdir()
    with tarfile.open(archive) as t:
        for member in t.getmembers():
            assert member.isfile() and not Path(member.name).is_absolute() and '..' not in Path(member.name).parts
        t.extractall(bundle,filter='data')
    manifest=json.loads((bundle/'manifest.json').read_text())
    live=tmp/'serving';live.mkdir()
    for path,digest in manifest['baseline'].items():
        data=subprocess.check_output(['git','show','a4a02cf4716b37e03bb9d0ff221745127c2c4807:'+path],cwd=root)
        if path=='analizmop/index.html':data=data.replace(b'manager-tasks.js?v=20261029-tasks-5',b'manager-tasks.js?v=20261117-tasks-6')
        assert hashlib.sha256(data).hexdigest()==digest,path
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
    protected=live/'api/config.local.php';protected.write_text('private fixture configuration')
    module.check(bundle,live,'baseline')
    victim=live/'api/max_messages_service.php';saved=victim.read_bytes();victim.write_bytes(saved+b'changed')
    try:module.check(bundle,live,'baseline');raise AssertionError('Changed MAX accepted')
    except RuntimeError:pass
    victim.write_bytes(saved)
    for path in manifest['payload']:
        f=live/path;f.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(bundle/'payload'/path,f)
    module.check(bundle,live,'installed')
    assert protected.read_text()=='private fixture configuration'
    victim=live/'api/client_identity_index.php';victim.unlink()
    try:module.check(bundle,live,'installed');raise AssertionError('Partial installation accepted')
    except RuntimeError:pass
print('firstvds_package: explicit code allowlist, baseline/installed gates, drift, partial release and config preservation OK')
