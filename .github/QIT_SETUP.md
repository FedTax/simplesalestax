# QIT Setup Instructions

## Prerequisites
1. WooCommerce.com Partner Developer account
2. Application password for your WooCommerce.com account

## GitHub Secrets Configuration
Add the following secrets to the repository:

- `QIT_USER`: Your WooCommerce.com username
- `QIT_APP_PASS`: Your WooCommerce.com application password

## Getting QIT Credentials
1. Log in to your WooCommerce.com account
2. Go to your account settings and generate an application password
3. Add both your username and application password as GitHub Secrets
4. The workflow will use `qit partner:add` to authenticate automatically

## Manual Testing

### Testing QIT workflow only:
1. Go to Actions tab in GitHub
2. Select "QIT Testing" workflow
3. Click "Run workflow"
4. Optionally specify plugin version

### Testing full deploy pipeline without WordPress.org deployment:
1. Go to Actions tab in GitHub
2. Select "Deploy" workflow
3. Click "Run workflow"
4. Check "Skip WordPress.org deployment (for testing)" option
5. This will run QIT tests + build process but skip actual deployment

## QIT Tests Included
- **Activation Tests**: Ensure the plugin activates without errors
- **Security Tests**: Check for security vulnerabilities

## Integration with Deploy Workflow
The deploy workflow now depends on successful QIT tests. This ensures that:
- QIT tests run automatically when a GitHub release is created
- WordPress.org deployment only happens after QIT validation passes
- Manual QIT testing can be performed before creating releases
