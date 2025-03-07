const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

// Remove SASS rule from the default config so we can define our own.
const defaultRules = defaultConfig.module.rules.filter((rule) => {
	return String(rule.test) !== String(/\.(sc|sa)ss$/);
});

module.exports = {
	...defaultConfig,
	entry: {
		'tax-exemption-block': path.resolve(
			process.cwd(),
			'assets/js/blocks/tax-exemption/index.js',
		),
		'tax-exemption-block-frontend':
			path.resolve(
				process.cwd(),
				'assets/js/blocks/tax-exemption/frontend.js',
			),
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultRules,
			{
				test: /\.(sc|sa)ss$/,
				exclude: /node_modules/,
				use: [
					MiniCssExtractPlugin.loader,
					{ loader: 'css-loader', options: { importLoaders: 1 } },
					{
						loader: 'sass-loader',
						options: {
							sassOptions: {
								includePaths: [
									'includes/vendor/woocommerce/woocommerce/plugins/woocommerce-blocks/assets/css/abstracts'
								],
							},
							additionalData: ( content, loaderContext ) => {
								const { resourcePath, rootContext } =
									loaderContext;
								const relativePath = path.relative(
									rootContext,
									resourcePath
								);

								if (
									relativePath.startsWith(
										'assets/css/abstracts/'
									) ||
									relativePath.startsWith(
										'assets\\css\\abstracts\\'
									)
								) {
									return content;
								}

								return (
									'@use "sass:math";' +
									'@use "sass:string";' +
									'@use "sass:color";' +
									'@use "sass:map";' +
									'@import "_colors"; ' +
									'@import "_variables"; ' +
									'@import "_breakpoints"; ' +
									'@import "_mixins"; ' +
									content
								);
							},
						},
					},
				],
			},
		],
	},
	plugins: [
		...defaultConfig.plugins.filter(
			(plugin) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
		new MiniCssExtractPlugin({
			filename: `[name].css`,
		}),
	],
	resolve: {
		alias: {
			'@woocommerce/base-components': path.resolve(
				process.cwd(),
				'includes/vendor/woocommerce/woocommerce/plugins/woocommerce-blocks/assets/js/base/components',
			),
			'@woocommerce/types': path.resolve(
				process.cwd(),
				'includes/vendor/woocommerce/woocommerce/plugins/woocommerce-blocks/assets/js/types',
			),
			'@woocommerce/block-settings': path.resolve(
				process.cwd(),
				'includes/vendor/woocommerce/woocommerce/plugins/woocommerce-blocks/assets/js/settings/blocks',
			),
		},
		extensions: [ '.js', '.jsx', '.ts', '.tsx' ],
	},
};
