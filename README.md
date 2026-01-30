# Grant CSV Importer WordPress Plugin

A WordPress plugin for importing grants and users from CSV files with automatic mapping to custom post types, ACF fields, and user roles.

## Features

- Upload and preview CSV files before importing
- Automatic field mapping from CSV columns to Grant custom post type and ACF fields
- Create WordPress users from Project Director information
- Duplicate detection for both grants (by grant number) and users (by email)
- Test import options (1 row, 5 rows, or all rows)
- Automatic phone number formatting with extension support
- Date conversion from M/D/YYYY to WordPress standard YYYY-MM-DD format
- No automatic email notifications for new users
- Detailed import results reporting

## Requirements

- WordPress 5.0 or higher
- Advanced Custom Fields (ACF) plugin
- Custom Post Type: `grant`
- Custom Taxonomy: `group-program` (assigned to Grant post type)
- Custom User Roles (see below)

## Required ACF Fields

The following ACF fields must be set up on the Grant post type:

- `grant_number` (Text)
- `city` (Text)
- `state` (Text)
- `grant_start_date` (Date Picker - format: Ymd)
- `grant_end_date` (Date Picker - format: Ymd)
- `contact_name` (Text)
- `contact_email` (Email)
- `contact_phone` (Text)
- `contact_phone_extension` (Text)

## Required Custom User Roles

The following user roles must be registered (with slugs):

- BHWET Para User [bhwet-para-user]
- BHWET Pro User [bhwet-pro-user]
- BHWET Social User [bhwet-social-user]
- GPE User [gpe-user]
- ISTP User [istp-user]
- OIFSP User [oifsp-user]
- AMF User [amf-user]

## Required Taxonomy Terms

The following taxonomy terms must exist in the `group-program` taxonomy:

- bhwet-para
- bhwet-pro
- bhwet-social
- gpe
- istp
- oifsp
- amf

## Installation

1. Upload the `grant-csv-importer.php` file to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Grant Importer' in the WordPress admin menu

## CSV File Format

The CSV file must contain the following columns (in any order):

- Organization Name
- Grant Number
- City
- State
- Current Project Period Start Date (format: M/D/YYYY)
- Current Project Period End Date (format: M/D/YYYY)
- Project Director - Name
- Project Director - Email
- Project Director - Phone

**Note:** The Grant Program is selected via dropdown during upload, not included in the CSV. Each CSV file should contain grants for a single Group Program.

## Field Mapping

| CSV Column / Input | Maps To |
|------------|---------|
| Group Program (dropdown selection) | Group Program Taxonomy + User Role |
| Organization Name | Grant Post Title |
| Grant Number | ACF Field: grant_number |
| City | ACF Field: city |
| State | ACF Field: state |
| Current Project Period Start Date | ACF Field: grant_start_date |
| Current Project Period End Date | ACF Field: grant_end_date |
| Project Director - Name | ACF Field: contact_name + User First/Last Name |
| Project Director - Email | ACF Field: contact_email + User Email (username is portion before @) |
| Project Director - Phone | ACF Fields: contact_phone + contact_phone_extension |

## Grant Program Mapping

The plugin automatically maps selected Group Program taxonomy terms to user roles:

| Group Program Taxonomy Slug | User Role |
|-------------------|-----------|
| bhwet-para | bhwet-para-user |
| bhwet-pro | bhwet-pro-user |
| bhwet-social | bhwet-social-user |
| gpe | gpe-user |
| istp | istp-user |
| oifsp | oifsp-user |
| amf | amf-user |

When you select a Group Program during upload, all grants in that CSV will be assigned to that taxonomy term, and all users created will be assigned the corresponding role.

## Usage

### Step 1: Upload CSV File
1. Go to 'Grant Importer' in the WordPress admin menu
2. Select the Group Program from the dropdown (e.g., BHWET-Pro, GPE, etc.)
3. Click 'Choose File' and select your CSV file
4. Click 'Upload and Preview'

### Step 2: Review and Import
1. Review the selected Group Program and preview showing the first 5 rows of data
2. Choose an import option:
   - **Import 1 Row (Test)**: Import only the first row to verify everything works
   - **Import 5 Rows (Test)**: Import the first 5 rows for further testing
   - **Import All Rows**: Import all data from the CSV file

### Step 3: Review Results
After import, you'll see a summary showing:
- Number of grants created
- Number of grants skipped (duplicates)
- Number of users created
- Number of users skipped (duplicates)
- Any errors that occurred

## How It Works

### Grant Creation
1. Checks if a grant with the same grant number already exists
2. If it exists, skips creation
3. If it doesn't exist, creates a new Grant post with:
   - Title set to Organization Name
   - Group Program taxonomy term assigned
   - All ACF fields populated from CSV data

### User Creation
1. Checks if a user with the same email already exists
2. If exists, skips creation
3. If doesn't exist, creates a new user with:
   - Username set to email prefix (portion before @, e.g., "heather.trepal" from "heather.trepal@utsa.edu")
   - Email address from CSV
   - First and last name parsed from Project Director Name
   - Role assigned based on Grant Program
   - Random secure password generated
   - **No email notification sent** (to be handled separately)

### Phone Number Handling
- Automatically formats phone numbers as: (###) ###-####
- Detects and separates extensions (e.g., "Ext: 2326" or "Ext 2326")
- Main phone stored in `contact_phone`
- Extension stored in `contact_phone_extension`

### Date Conversion
- Converts dates from M/D/YYYY format to YYYY-MM-DD (WordPress standard)
- Works with single or double-digit months and days

## Notes

- Users are created with random passwords and will need to use "Forgot Password" to set their own
- No email notifications are sent during import - plan to communicate with users separately
- The plugin creates an upload directory at `/wp-uploads/grant-imports/` to store CSV files temporarily
- CSV data is stored in WordPress transients for 1 hour between upload and import steps
- After a full import, temporary data is automatically cleaned up

## Troubleshooting

### "CSV data not found" error
- This can happen if more than 1 hour has passed between upload and import
- Simply re-upload the CSV file and try again

### Grants or users not being created
- Verify that the Grant post type is registered
- Verify that all required ACF fields exist with correct field names
- Verify that taxonomy terms exist in the group-program taxonomy
- Verify that custom user roles are registered
- Check the error messages in the import results

### ACF fields not saving
- Make sure you're using `update_field()` from ACF (not `update_post_meta()`)
- Verify field names match exactly (they're case-sensitive)
- Ensure ACF is activated and functioning properly

## Extending the Plugin

The plugin can be extended by:
- Adding filters to the mapping functions
- Customizing the CSV parsing logic
- Adding additional validation rules
- Implementing custom import actions

## Security

- Only users with `manage_options` capability can access the importer
- All inputs are sanitized and validated
- Nonce verification on all form submissions
- File type verification for uploads

## Support

For issues or questions about this plugin, please contact your WordPress administrator.

## Version History

### 1.0.0
- Initial release
- CSV upload and preview functionality
- Grant and user creation with duplicate detection
- Phone number formatting and extension extraction
- Date conversion
- Test import options (1, 5, or all rows)
