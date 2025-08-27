# QIT Setup Instructions

## Prerequisites
1. WooCommerce.com Partner Developer account
2. Application password for your WooCommerce.com account

## GitHub Secrets Configuration
Add the following secrets to the repository:

- `QIT_USER`: Your WooCommerce.com username
- `QIT_TOKEN`: Your WooCommerce.com QIT Token (not application password)

## Getting QIT Credentials
1. Log in to your WooCommerce.com account
2. Go to the QIT authorization page to generate a QIT Token
3. Add both your username and QIT Token as GitHub Secrets
4. The workflow will use `qit partner:add` to authenticate automatically

**Note**: QIT Tokens have replaced application passwords. Regular application passwords will not work.

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
- **Custom E2E Tests**: Comprehensive end-to-end testing including activation, basic functionality, and compatibility checks
- **Note**: Uses custom E2E tests instead of managed tests since the plugin is not registered in the WooCommerce Marketplace

## Separate QIT Testing Workflow
The QIT testing workflow is completely separate from the deploy process:
- QIT tests are triggered manually when needed for testing
- Deploy workflow runs independently without QIT dependency
- Provides flexibility to test QIT validation before releases
- No automatic QIT testing on releases (manual control only)
