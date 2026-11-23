"""Measure real V3 capture through WooCommerce's local CGI checkout and order API."""
import argparse
import concurrent.futures
import itertools
import json
import os
from pathlib import Path
import random
import shutil
import subprocess
import time
from urllib.parse import urlencode, urlsplit
from uuid import uuid4

HERE = Path(__file__).resolve().parent
PLUGIN = HERE.parents[1]
WP = PLUGIN.parents[2]
CELLS = list(itertools.product(('realtime', 'import'), ('processing', 'completed'), (1, 2), (False, True)))


def prepare(directory, site, background, verify):
    directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    (directory / 'mu').mkdir()
    plugins = directory / 'plugins'
    shadow = plugins / 'simple-sales-tax'
    shadow.mkdir(parents=True)
    (plugins / 'woocommerce').symlink_to(PLUGIN.parent / 'woocommerce', target_is_directory=True)
    shutil.copy2(PLUGIN / 'simple-sales-tax.php', shadow)
    shutil.copytree(PLUGIN / 'includes', shadow / 'includes', symlinks=True)
    for asset in ('assets', 'languages'):
        (shadow / asset).symlink_to(PLUGIN / asset, target_is_directory=True)
    controller = shadow / 'includes/class-sst-order-controller.php'
    original = controller.read_text()
    call = 'return $sst_order->do_capture();'
    if original.count(call) != 1:
        raise RuntimeError('Capture entry point changed. Recheck the timing probe.')
    instrumented = original.replace(call, 'return sst_timing_capture( $sst_order );')
    if background:
        queue = 'return SST_Deferred_Capture::queue( $order );'
        if instrumented.count(queue) != 1:
            raise RuntimeError('Expected background capture entry point')
        instrumented = instrumented.replace(queue, 'return sst_timing_queue( $order );')
    controller.write_text(instrumented)
    run = uuid4().hex[:12]
    config = dict(run=run, background=background, verify=verify, email=f'dev6611-{run}@example.test', site=site,
                  credentials=str(PLUGIN / 'cypress/credentials.local.json'))
    (directory / 'run.json').write_text(json.dumps(config))


def request(directory, socket, cell, method, route, body=None, token=None):
    config = json.loads((directory / 'run.json').read_text())
    sample = uuid4().hex
    env = dict(os.environ, SST_TIMING_DIR=str(directory), SST_TIMING_CELL=json.dumps(dict(cell, sample=sample)),
               REDIRECT_STATUS='200', SERVER_NAME=urlsplit(config['site']).hostname,
               HTTP_HOST=urlsplit(config['site']).hostname, SERVER_PORT='80', SERVER_PROTOCOL='HTTP/1.1',
               REMOTE_ADDR='127.0.0.1', REQUEST_METHOD=method, SCRIPT_FILENAME=str(WP / 'index.php'),
               SCRIPT_NAME='/index.php', QUERY_STRING=urlencode(dict(rest_route=route)), REQUEST_URI='/wp-json' + route)
    data = json.dumps(body).encode() if body is not None else b''
    env.update(CONTENT_TYPE='application/json', CONTENT_LENGTH=str(len(data)))
    if token:
        env['HTTP_CART_TOKEN'] = token
    command = ['php-cgi', '-d', f'mysqli.default_socket={socket}', '-d', f'auto_prepend_file={HERE / "prepend.php"}',
               '-d', 'display_errors=0', '-d', 'max_execution_time=120']
    started = time.perf_counter()
    proc = subprocess.run(command, env=env, input=data, capture_output=True, timeout=150)
    elapsed = (time.perf_counter() - started) * 1000
    headers, _, content = proc.stdout.partition(b'\r\n\r\n')
    headers = dict((k.strip().lower(), v.strip()) for line in headers.decode(errors='replace').splitlines()
                   for k, sep, v in [line.partition(':')] if sep)
    timing = directory / (sample + '.json')
    metrics = json.loads(timing.read_text()) if timing.exists() else {}
    if timing.exists():
        timing.unlink()
    metrics['request_ms'] = elapsed
    try:
        result = json.loads(content)
    except ValueError:
        # Do not print HTML, HTTP headers, or response bodies containing customer information.
        raise RuntimeError(f'{method} {route}: invalid CGI response, status {headers.get("status")}, exit {proc.returncode}')
    if headers.get('status', '200').split()[0] not in ('200', '201') or isinstance(result, dict) and result.get('code'):
        raise RuntimeError(f'{method} {route}: {headers.get("status")} {result.get("code")}')
    return result, headers.get('cart-token', token), metrics


def measure(directory, socket, config, index, cell):
    mode, trigger, packages, enabled = cell
    context = dict(mode=mode, trigger=trigger, enabled=enabled, step='cart')
    row = dict(index=index, mode=mode, trigger=trigger, packages=packages, enabled=enabled)
    try:
        token = None
        for pid in config['products'][:packages]:
            _, token, _ = request(directory, socket, context, 'POST', '/wc/store/v1/cart/add-item',
                                  dict(id=pid, quantity=2 if packages == 1 else 1), token)
            if not token:
                raise RuntimeError('Cart-Token missing')
        address = dict(first_name='Capture', last_name='Timing', address_1='323 Washington Ave N', city='Minneapolis',
                       state='MN', postcode='55401', country='US', email=config['email'], phone='6125550100')
        order, _, metrics = request(directory, socket, dict(context, step='checkout'), 'POST', '/wc/store/v1/checkout',
                                    dict(billing_address=address, payment_method='cod'), token)
        oid = order['order_id']
        row['order'] = oid
        if order.get('status') != 'processing':
            raise RuntimeError('Checkout did not reach Processing: ' + str(order.get('status')))
        if trigger == 'completed':
            order, _, metrics = request(directory, socket, dict(context, step='complete', order=oid),
                                        'PUT', f'/wc/v3/orders/{oid}', dict(status='completed'))
            if order.get('status') != 'completed':
                raise RuntimeError('Order did not reach Completed')
        row.update(metrics)
        writes = [c for c in metrics['calls'] if c['capture'] and c['route'] in ('POST /orders', 'POST /carts/orders')]
        if config.get('background'):
            if 'trigger_at' not in metrics or metrics['capture_ms'] is not None or metrics['calls']:
                raise RuntimeError('Trigger request contacted TaxCloud or did not enqueue')
            result, _, worker = request(directory, socket, dict(context, step='worker', order=oid), 'GET', '/')
            row['worker_calls'] = worker['calls']
            if result != dict(status='captured', tax_status='captured'):
                raise RuntimeError('Worker failed: ' + str(result.get('status')))
            if sum(c['route'] == 'POST /orders' and c['status'] == 201 for c in worker['calls']) != packages:
                raise RuntimeError('Worker did not confirm the expected number of packages')
            row.update(capture_ms=0, worker_ms=worker['worker_ms'],
                       confirmation_ms=(worker['confirmed_at'] - metrics['trigger_at']) * 1000)
        elif metrics['capture_ms'] is None or bool(metrics['captured']) != enabled or len(writes) != (packages if enabled else 0):
            raise RuntimeError('Unexpected capture result or package count')
        row['ok'] = True
    except (RuntimeError, KeyError, subprocess.TimeoutExpired) as error:
        row.update(ok=False, error=str(error) if not isinstance(error, subprocess.TimeoutExpired) else 'CGI timeout')
    return row


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True, help='Private run directory outside the web root')
    parser.add_argument('--socket', required=True)
    parser.add_argument('--site', default='http://dev-taxcloud.local')
    parser.add_argument('--background', action='store_true', help='Measure opt-in Data Import with an immediate private Action Scheduler runner')
    parser.add_argument('--samples', type=int, default=50)
    parser.add_argument('--workers', type=int, default=1)
    parser.add_argument('--verify', action='store_true', help='Run local HPOS and legacy regression cases with simulated TaxCloud responses')
    parser.add_argument('--confirm-test-connections', action='store_true')
    args = parser.parse_args()
    if args.verify:
        args.background = True
    elif not args.confirm_test_connections:
        parser.error('Real calls require --confirm-test-connections.')
    directory = args.directory.resolve()
    if WP in directory.parents or not (WP / 'wp-load.php').exists() or not Path(args.socket).is_socket():
        parser.error('Run from a LocalWP checkout with its MySQL socket. Keep results outside the site.')
    if not 1 <= args.samples <= 50 or not 1 <= args.workers <= 4:
        parser.error('Use 1–50 samples per group and 1–4 workers.')
    if not directory.exists():
        prepare(directory, args.site, args.background, args.verify)
    cfg = json.loads((directory / 'run.json').read_text())
    if cfg['site'] != args.site or bool(cfg.get('background')) != args.background or bool(cfg.get('verify')) != args.verify:
        parser.error('Run directory belongs to a different site.')
    if 'products' not in cfg:
        cfg['products'], _, _ = request(directory, args.socket, dict(step='setup', mode='import', trigger='completed',
                                                                    enabled=False), 'GET', '/')
        (directory / 'run.json').write_text(json.dumps(cfg))
    if args.verify:
        for storage in ('hpos', 'legacy'):
            result, _, _ = request(directory, args.socket, dict(step='verify', sample='', mode='import', trigger='both',
                                                                enabled=True, storage=storage), 'GET', '/')
            print(storage, result, flush=True)
            if not result.get('passed'):
                raise SystemExit(1)
        return
    output = directory / 'samples.jsonl'
    existing = [json.loads(s) for s in output.read_text().splitlines()] if output.exists() else []
    seen = {(r['index'], r['mode'], r['trigger'], r['packages'], r['enabled']) for r in existing}
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as workers, output.open('a') as handle:
        for index in range(1, args.samples + 1):
            cells = [c for c in CELLS if c[0] == 'import' and c[3]] if args.background else list(CELLS)
            random.Random(index).shuffle(cells)
            pending = [c for c in cells if (index, *c) not in seen]
            for row in workers.map(lambda c: measure(directory, args.socket, cfg, index, c), pending):
                handle.write(json.dumps(row) + '\n')
                handle.flush()
                print(index, row['mode'], row['trigger'], row['packages'], row['enabled'],
                      'ok' if row['ok'] else row['error'], flush=True)
            if index == 1 and any(not json.loads(line)['ok'] for line in output.read_text().splitlines()):
                raise SystemExit('Inspect the pilot failures before collecting more samples.')


if __name__ == '__main__':
    main()
