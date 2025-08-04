# QIT Setup Instructions

## Prerequisites
1. WooCommerce.com Partner Developer account
2. QIT CLI authentication tokens

## GitHub Secrets Configuration
Add the following secrets to the repository:

- `QIT_USER`: Your WooCommerce.com username
- `QIT_APP_PASS`: Application password from `qit connect` command

## Getting QIT Credentials
1. Install QIT CLI locally: `composer global require woocommerce/qit-cli`
2. Run: `qit connect`
3. Follow the authentication flow
4. Copy the generated credentials to GitHub Secrets

## Manual Testing
To run QIT tests manually before a release:
1. Go to Actions tab in GitHub
2. Select "QIT Testing" workflow
3. Click "Run workflow"
4. Optionally specify plugin version

## QIT Tests Included
- **Activation Tests**: Ensure the plugin activates without errors
- **Security Tests**: Check for security vulnerabilities

## Integration with Deploy Workflow
The deploy workflow now depends on successful QIT tests. This ensures that:
- QIT tests run automatically when a GitHub release is created
- WordPress.org deployment only happens after QIT validation passes
- Manual QIT testing can be performed before creating releases
