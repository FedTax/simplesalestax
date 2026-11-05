/* DEV-10621: run the real admin request filter without a browser. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const files = ['assets/js/meta-box.js'];
if (fs.existsSync(path.join(__dirname, '../assets/js/meta-box.min.js'))) {
	files.push('assets/js/meta-box.min.js');
}

for (const file of files) {
	let viewMethods;
	const element = { is: () => true, val: () => '42', on: () => {} };
	function jQuery(value) {
		if (typeof value === 'function') value(jQuery);
		return element;
	}
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', file), 'utf8'), {
		jQuery,
		wp: { template: () => () => '' },
		SSTMetaBox: { selected_certificate: '', order_status: 'pending' },
		Backbone: {
			Model: { extend: () => function () {} },
			View: { extend: (methods) => {
				viewMethods = methods;
				return function () { this.render = () => {}; };
			} },
		},
	});
	for (const id of ['11111111-2222-3333-4444-555555555555', '', 'single-purchase', 'certificate&order_id=999&items=bad+data#fragment', 'O:8:"stdClass":0:{}']) {
		const settings = { data: 'action=woocommerce_calc_line_taxes&order_id=123&items=original' };
		viewMethods.filterRecalcRequest.call({ model: { get: () => id } }, null, null, settings);
		const posted = new URLSearchParams(settings.data);
		assert.deepEqual(posted.getAll('order_id'), ['123'], `${file}: selection cannot override the target order`);
		assert.equal(posted.get('items'), 'original', `${file}: selection cannot override items`);
		assert.equal(posted.get('exemption_certificate'), id, `${file}: selection remains one encoded value`);
	}
	for (const data of ['action=other&order_id=123', { action: 'woocommerce_calc_line_taxes' }]) {
		const settings = { data };
		viewMethods.filterRecalcRequest.call({ model: { get: () => 'test' } }, null, null, settings);
		assert.equal(settings.data, data, `${file}: unrelated requests are unchanged`);
	}
}
console.log(`Certificate recalculation security regression checks passed (${files.length} assets).`);
