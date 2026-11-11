"""Incremental deploy of the component to the fordev development stand over FTPS.

Run by the architect session only. Uploads the files under core/components/modxmcp and
assets/components/modxmcp that changed since the last deploy, verifies each upload byte for
byte, uploads src/ before everything else and api.php last, and removes files that no longer
exist. FTP credentials come from the ignored .env and are never printed. The state of the
last deploy is kept in the ignored .local/deploy-fordev.json.

    python3 scripts/stand/deploy.py
"""
import ftplib, hashlib, io, json, os, ssl, subprocess, sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SITE = '.'   # the FTP root is the site root

def env():
    out = {}
    for line in open(os.path.join(REPO, '.env'), encoding='utf-8'):
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            k, v = line.split('=', 1)
            out[k.strip()] = v.strip().strip('"\'')
    return out

def connect():
    e = env()
    host, port = e['FTP_HOST'], int(e.get('FTP_PORT') or 21)
    try:
        ftp = ftplib.FTP_TLS(timeout=30)
        ftp.connect(host, port)
        ftp.auth()
        ftp.login(e['FTP_USER'], e['FTP_PASSWORD'])
        ftp.prot_p()
        mode = 'FTPS'
    except Exception as err:
        ftp = ftplib.FTP(timeout=30)
        ftp.connect(host, port)
        ftp.login(e['FTP_USER'], e['FTP_PASSWORD'])
        mode = 'plain FTP (TLS unavailable: %s)' % type(err).__name__
    ftp.set_pasv(True)
    return ftp, host, mode

def walk(ftp, path):
    """Yield (relative file path, size) under path."""
    stack = ['']
    while stack:
        rel = stack.pop()
        try:
            entries = list(ftp.mlsd(path + ('/' + rel if rel else '')))
        except ftplib.error_perm:
            return
        for name, facts in entries:
            if name in ('.', '..'):
                continue
            child = (rel + '/' if rel else '') + name
            if facts.get('type') == 'dir':
                stack.append(child)
            elif facts.get('type') == 'file':
                yield child, int(facts.get('size', 0))

def get(ftp, path):
    buf = io.BytesIO()
    ftp.retrbinary('RETR ' + path, buf.write)
    return buf.getvalue()

def mkdirs(ftp, path):
    parts = path.split('/')
    for i in range(1, len(parts) + 1):
        try:
            ftp.mkd('/'.join(parts[:i]))
        except ftplib.error_perm:
            pass

def put(ftp, path, data):
    ftp.storbinary('STOR ' + path, io.BytesIO(data))

AREAS = ('core/components/modxmcp', 'assets/components/modxmcp')
STATE = os.path.join(REPO, '.local', 'deploy-fordev.json')
git = lambda *a: subprocess.run(['git', '-C', REPO] + list(a), capture_output=True, check=True).stdout

if os.path.exists(STATE):
    deployed = json.load(open(STATE))
else:
    sys.exit('No deploy state (.local/deploy-fordev.json). Compare the stand with the repository by hand before the first deploy.')

local = {}
for path in git('ls-files', '--cached', '--others', '--exclude-standard', '--', *AREAS).decode().split():
    full = os.path.join(REPO, path)
    if os.path.isfile(full):
        local[path] = hashlib.sha256(open(full, 'rb').read()).hexdigest()

upload = sorted(p for p in local if deployed.get(p) != local[p])
delete = sorted(p for p in deployed if p not in local)
order = lambda p: (2 if p.endswith('assets/components/modxmcp/api.php') else 0 if '/src/' in p else 1, p)
ftp, host, mode = connect()
print('host:', host, '| mode:', mode, '| upload:', len(upload), '| delete:', len(delete))
for path in sorted(upload, key=order):
    data = open(os.path.join(REPO, path), 'rb').read()
    mkdirs(ftp, SITE + '/' + os.path.dirname(path))
    put(ftp, SITE + '/' + path, data)
    if get(ftp, SITE + '/' + path) != data:
        print('VERIFY FAILED', path); sys.exit(1)
    deployed[path] = local[path]
    json.dump(deployed, open(STATE, 'w'))
for path in delete:
    try: ftp.delete(SITE + '/' + path)
    except Exception as e: print('delete failed', path, e)
    deployed.pop(path, None)
json.dump(deployed, open(STATE, 'w'))
print('done')
ftp.quit()
