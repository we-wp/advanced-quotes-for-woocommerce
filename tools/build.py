"""Build the installable plugin ZIP with isolated, pinned PDF dependencies.

Run from the repository root: python3 tools/build.py
Composer plugins and scripts stay disabled. Two runs from the same source produce the same bytes.
"""
from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
header = (root / 'advanced-quotes-for-woocommerce.php').read_text()
version = re.search(r'^ \* Version: (\S+)$', header, re.M).group(1)
prefix = 'WeWPQuotesVendor'

subprocess.run(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction'], cwd=root, check=True)
subprocess.run(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction'], cwd=root / 'tools', check=True)
build = root / 'build'
build.mkdir(exist_ok=True)
scoped = build / 'scoped'
if scoped.exists():
    shutil.rmtree(scoped)
subprocess.run([str(root / 'tools/vendor/bin/php-scoper'), 'add-prefix', 'vendor', '--prefix=' + prefix, '--output-dir=build/scoped', '--no-config', '--no-interaction', '--stop-on-failure'], cwd=root, check=True)

pkg = build / 'advanced-quotes-for-woocommerce'
if pkg.exists():
    shutil.rmtree(pkg)
pkg.mkdir()
for name in ['src', 'assets', 'fonts', 'templates', 'advanced-quotes-for-woocommerce.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'THIRD-PARTY-NOTICES.md']:
    src = root / name
    if src.is_dir():
        shutil.copytree(src, pkg / name)
    else:
        shutil.copy2(src, pkg / ('THIRD-PARTY-NOTICES.txt' if name == 'THIRD-PARTY-NOTICES.md' else name))
shutil.copytree(scoped, pkg / 'vendor')
shutil.rmtree(pkg / 'vendor/composer')
(pkg / 'vendor/autoload.php').unlink()
for pattern in ['**/bin', '**/tests', '**/.github']:
    for path in list((pkg / 'vendor').glob(pattern)):
        if path.is_dir():
            shutil.rmtree(path)
for path in list((pkg / 'vendor').rglob('rector*.php')):
    path.unlink()

# PHP-Scoper cannot resolve class names built from strings inside upstream factories.
for dep in (pkg / 'vendor').rglob('*.php'):
    text = dep.read_text()
    for name in ['Dompdf', 'FontLib', 'Svg']:
        for quote in ['"', "'"]:
            for lead in ['', '\\', '\\\\']:
                text = text.replace(quote + lead + name + '\\', quote + lead + prefix + '\\\\' + name + '\\')
    for partial in ['TrueType', 'OpenType', 'WOFF', 'EOT']:
        text = text.replace('"' + prefix + '\\\\' + partial, '"' + partial)
    if dep.as_posix().endswith('FontLib/TrueType/File.php'):
        text = text.replace('return $class_parts[1];', 'return $class_parts[2];')
    dep.write_text(text)
renderer = pkg / 'src/Renderer.php'
renderer.write_text(renderer.read_text().replace('use Dompdf\\', 'use ' + prefix + '\\Dompdf\\'))

safe = json.loads((root / 'vendor/thecodingmachine/safe/composer.json').read_text())
manifest = {
    'name': 'we-wp/advanced-quotes-runtime',
    'autoload': {'classmap': ['src/', 'vendor/'], 'files': ['vendor/thecodingmachine/safe/' + f for f in safe['autoload']['files']]},
    'config': {'allow-plugins': False, 'autoloader-suffix': 'WeWPQuotes' + version.replace('.', '')},
}
(pkg / 'composer.json').write_text(json.dumps(manifest))
subprocess.run(['composer', 'dump-autoload', '--classmap-authoritative', '--no-scripts', '--no-interaction'], cwd=pkg, check=True)

out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / f'advanced-quotes-for-woocommerce-{version}.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for path in sorted(pkg.rglob('*')):
        if path.is_file():
            info = zipfile.ZipInfo(str(path.relative_to(build)), (2026, 9, 27, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, path.read_bytes())
print(json.dumps({'file': str(archive), 'bytes': archive.stat().st_size, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}))
