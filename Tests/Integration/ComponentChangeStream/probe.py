import concurrent.futures
import http.client
import json
import os
import shutil
import uuid
import subprocess
import time
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parent
repo = root.parents[2]
network = 'frontend-studio-change-stream-' + uuid.uuid4().hex[:8]
httpd_name = network + '-apache'
fpm_name = network + '-fpm'
names = [httpd_name, fpm_name]
logs_dir = repo / 'var' / network
logs_dir.mkdir(parents=True)
probe_dir = logs_dir / 'probe'
probe_dir.mkdir()
shutil.copy(root / 'fpm.conf', probe_dir / 'fpm.conf')
for filename in ['stream.php', 'health.php']:
    shutil.copy(root / (filename + '.template'), probe_dir / filename)

def run(*args):
    return subprocess.check_output(args, text=True).strip()

def health(port):
    start = time.monotonic()
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/health.php', timeout=8) as response:
        assert json.load(response)['healthy']
    return time.monotonic() - start

def status(port):
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/status.php?json', timeout=3) as response:
        return json.load(response)

def stream(port, delay=0):
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
    connection.request('GET', f'/stream.php?delay={delay}')
    response = connection.getresponse()
    assert response.readline() == b'event: ready\n'
    response.readline()
    response.readline()
    return connection, response

def stop():
    for name in names:
        subprocess.run(['docker', 'stop', '--time', '2', name], capture_output=True)
    subprocess.run(['docker', 'network', 'rm', network], capture_output=True)

try:
    run('docker', 'network', 'create', network)
    run('docker', 'run', '--detach', '--rm', '--user', f'{os.getuid()}:{os.getgid()}', '--network', network,
        '--name', fpm_name, '--network-alias', 'issue29-fpm', '-e', 'XDEBUG_MODE=off', '-v', f'{probe_dir}:/probe:ro',
        '-v', f'{repo}/Classes/Http:/source:ro',
        '-v', f'{repo}/vendor:/vendor:ro',
        'ghcr.io/typo3/core-testing-php85:latest', 'php-fpm', '-F', '-y', '/probe/fpm.conf')
    run('docker', 'run', '--detach', '--rm', '--network', network, '--name', httpd_name,
        '-p', '127.0.0.1::80', '-v', f'{root}/httpd.conf:/usr/local/apache2/conf/httpd.conf:ro',
        'httpd:2.4-alpine')
    port = int(run('docker', 'port', httpd_name, '80/tcp').rsplit(':', 1)[1])
    for attempt in range(30):
        try:
            health(port)
            break
        except Exception:
            time.sleep(0.1)
    print('Apache/FastCGI baseline:', status(port), flush=True)
    first, response1 = stream(port)
    second, response2 = stream(port)
    print('Two streams:', status(port), flush=True)
    with concurrent.futures.ThreadPoolExecutor() as executor:
        queued = executor.submit(health, port)
        time.sleep(2)
        assert not queued.done(), 'Expected a queued request with both workers occupied'
        response1.close()
        first.close()
        elapsed = queued.result(timeout=6)
        print(f'Health recovered after {elapsed:.3f}s including 2s before disconnect.', flush=True)
    response2.close()
    second.close()
    for _ in range(50):
        current = status(port)
        if current['active processes'] == 0:
            break
        time.sleep(0.1)
    assert current['active processes'] == 0, current
    print('After both disconnects:', current, flush=True)
    print('Server ready for browser probe on port', port, flush=True)
    # Closing during the initial scan cannot interrupt that synchronous operation,
    # but the next padded heartbeat must still release the worker.
    start = time.monotonic()
    delayed, delayed_response = stream(port, delay=1500)
    assert time.monotonic() - start < 1, 'ready must arrive before the initial snapshot'
    delayed_response.close()
    delayed.close()
    for _ in range(120):
        if status(port)['active processes'] == 0:
            break
        time.sleep(0.1)
    assert status(port)['active processes'] == 0, 'disconnect during a snapshot left a worker active'
    print(f'Disconnect during a 1.5s snapshot released the worker after {time.monotonic() - start:.3f}s.', flush=True)
    subprocess.run(['node', str(root / 'browser.mjs'), str(port)], check=True)
finally:
    for name in names:
        logs = subprocess.run(['docker', 'logs', name], text=True, capture_output=True)
        (logs_dir / f'{name}.log').write_text(logs.stdout + logs.stderr)
    stop()
    print('Server logs:', logs_dir, flush=True)
