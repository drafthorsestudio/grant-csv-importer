<?php
/**
 * Plugin Name:BHWISE Grant CSV Importer
 * Description: Import grants and users from CSV files with mapping to custom post types and taxonomies
 * Version: 1.0.0
 * Author: KC Web Programmers
 * Text Domain: grant-csv-importer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Grant_CSV_Importer {
    
    private $upload_dir;
    private $csv_data = [];
    private $import_results = [];
    
    public function __construct() {
        // Set upload directory
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/grant-imports/';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Handle form submissions
        add_action('admin_init', [$this, 'handle_form_submission']);
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=grant',
            'Grant CSV Importer',
            'Import Grants',
            'manage_options',
            'grant-csv-importer',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submission() {
        if (!isset($_POST['grant_importer_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['grant_importer_nonce'], 'grant_importer_upload')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle CSV upload
        if (isset($_POST['upload_csv']) && !empty($_FILES['csv_file']['name'])) {
            $this->handle_csv_upload();
        }
        
        // Handle import execution
        if (isset($_POST['execute_import']) && isset($_POST['csv_filename'])) {
            $this->execute_import();
        }
        
        // Handle clear/reset
        if (isset($_POST['clear_import'])) {
            $this->clear_import_data();
        }
    }
    
    /**
     * Handle CSV file upload
     */
    private function handle_csv_upload() {
        $file = $_FILES['csv_file'];
        
        // Get selected group program term
        $group_program_term = isset($_POST['group_program_term']) ? sanitize_text_field($_POST['group_program_term']) : '';
        
        if (empty($group_program_term)) {
            add_settings_error(
                'grant_importer',
                'missing_term',
                'Please select a Group Program.',
                'error'
            );
            return;
        }
        
        // Check file type
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'csv') {
            add_settings_error(
                'grant_importer',
                'invalid_file_type',
                'Please upload a CSV file.',
                'error'
            );
            return;
        }
        
        // Move uploaded file
        $filename = 'grant_import_' . time() . '.csv';
        $destination = $this->upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Parse CSV
            $this->csv_data = $this->parse_csv($destination);
            
            if (empty($this->csv_data)) {
                add_settings_error(
                    'grant_importer',
                    'empty_csv',
                    'No valid data found in CSV file. Please ensure the file has a header row and at least one data row, and that all rows have the same number of columns.',
                    'error'
                );
                return;
            }
            
            // Validate required columns
            $required_columns = [
                'Organization Name',
                'Grant Number',
                'City',
                'State',
                'Current Project Period Start Date',
                'Current Project Period End Date',
                'Project Director - Name',
                'Project Director - Email',
                'Project Director - Phone'
            ];
            
            $first_row = reset($this->csv_data);
            $csv_columns = array_keys($first_row);
            $missing_columns = array_diff($required_columns, $csv_columns);
            
            if (!empty($missing_columns)) {
                add_settings_error(
                    'grant_importer',
                    'missing_columns',
                    'Missing required columns: ' . implode(', ', $missing_columns) . '. Found columns: ' . implode(', ', $csv_columns),
                    'error'
                );
                return;
            }
            
            // Store filename and selected term in transient for next step
            set_transient('grant_importer_csv_file', $filename, HOUR_IN_SECONDS);
            set_transient('grant_importer_csv_data', $this->csv_data, HOUR_IN_SECONDS);
            set_transient('grant_importer_group_program', $group_program_term, HOUR_IN_SECONDS);
            
            add_settings_error(
                'grant_importer',
                'upload_success',
                sprintf('CSV file uploaded successfully with %d row(s). Please review the mapping below.', count($this->csv_data)),
                'success'
            );
        } else {
            add_settings_error(
                'grant_importer',
                'upload_failed',
                'Failed to upload file.',
                'error'
            );
        }
    }
    
    /**
     * Clear import data and start over
     */
    private function clear_import_data() {
        // Delete transients
        delete_transient('grant_importer_csv_file');
        delete_transient('grant_importer_csv_data');
        delete_transient('grant_importer_results');
        delete_transient('grant_importer_group_program');
        
        // Optionally delete uploaded CSV files from directory
        // Uncomment if you want to clean up old files
        // $files = glob($this->upload_dir . 'grant_import_*.csv');
        // foreach ($files as $file) {
        //     if (is_file($file)) {
        //         unlink($file);
        //     }
        // }
        
        add_settings_error(
            'grant_importer',
            'cleared',
            'Import data cleared. You can start over with a new CSV file.',
            'success'
        );
        
        // Redirect to clear POST data
        wp_redirect(admin_url('admin.php?page=grant-csv-importer'));
        exit;
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv($file_path) {
        $data = [];
        
        if (!file_exists($file_path)) {
            return $data;
        }
        
        if (($handle = fopen($file_path, 'r')) !== false) {
            // Get headers from first row
            $headers = fgetcsv($handle);
            
            if (!$headers) {
                fclose($handle);
                return $data;
            }
            
            // Clean headers - remove BOM and trim
            $headers = array_map(function($header) {
                // Remove UTF-8 BOM if present
                $header = str_replace("\xEF\xBB\xBF", '', $header);
                return trim($header);
            }, $headers);
            
            $row_count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                    $row_count++;
                } else {
                    // Log mismatched row for debugging
                    error_log("Grant Importer: Row " . ($row_count + 2) . " has " . count($row) . " columns, expected " . count($headers));
                }
            }
            
            fclose($handle);
        }
        
        return $data;
    }
    
    /**
     * Execute the import
     */
    private function execute_import() {
        $csv_filename = sanitize_text_field($_POST['csv_filename']);
        $import_count = sanitize_text_field($_POST['import_count']);
        
        // Get CSV data and group program from transient
        $csv_data = get_transient('grant_importer_csv_data');
        $group_program_slug = get_transient('grant_importer_group_program');
        
        if (!$csv_data) {
            add_settings_error(
                'grant_importer',
                'no_data',
                'CSV data not found. Please upload the file again.',
                'error'
            );
            return;
        }
        
        if (!$group_program_slug) {
            add_settings_error(
                'grant_importer',
                'no_term',
                'Group Program not found. Please upload the file again.',
                'error'
            );
            return;
        }
        
        // Determine how many rows to import
        $rows_to_import = $csv_data;
        if ($import_count === '1') {
            $rows_to_import = array_slice($csv_data, 0, 1);
        } elseif ($import_count === '5') {
            $rows_to_import = array_slice($csv_data, 0, 5);
        }
        
        // Process imports
        $this->import_results = [
            'grants_created' => 0,
            'grants_skipped' => 0,
            'users_created' => 0,
            'users_skipped' => 0,
            'errors' => []
        ];
        
        foreach ($rows_to_import as $index => $row) {
            $this->process_row($row, $index, $group_program_slug);
        }
        
        // Store results in transient
        set_transient('grant_importer_results', $this->import_results, HOUR_IN_SECONDS);
        
        // Clear CSV data if all rows were imported
        if ($import_count === 'all') {
            delete_transient('grant_importer_csv_file');
            delete_transient('grant_importer_csv_data');
            delete_transient('grant_importer_group_program');
        }
        
        // Redirect to avoid resubmission
        wp_redirect(add_query_arg('import_complete', '1', admin_url('admin.php?page=grant-csv-importer')));
        exit;
    }
    
    /**
     * Process a single CSV row
     */
    private function process_row($row, $index, $group_program_slug) {
        // Extract data from row
        $org_name = trim($row['Organization Name']);
        $grant_number = trim($row['Grant Number']);
        $city = trim($row['City']);
        $state = trim($row['State']);
        $start_date = $this->convert_date($row['Current Project Period Start Date']);
        $end_date = $this->convert_date($row['Current Project Period End Date']);
        $pd_name = trim($row['Project Director - Name']);
        $pd_email = trim($row['Project Director - Email']);
        $pd_phone = trim($row['Project Director - Phone']);
        
        // Parse phone number
        $phone_data = $this->parse_phone_number($pd_phone);
        
        // Map group program slug to user role
        $user_role = $this->map_taxonomy_slug_to_role($group_program_slug);
        
        // Check if grant already exists
        $existing_grant = $this->get_grant_by_number($grant_number);
        
        if ($existing_grant) {
            $this->import_results['grants_skipped']++;
            $grant_id = $existing_grant->ID;
        } else {
            // Create grant post
            $grant_id = $this->create_grant_post($org_name, $grant_number, $city, $state, $start_date, $end_date, $pd_name, $pd_email, $phone_data, $group_program_slug);
            
            if (is_wp_error($grant_id)) {
                $this->import_results['errors'][] = "Row " . ($index + 1) . ": Failed to create grant - " . $grant_id->get_error_message();
                return;
            }
            
            $this->import_results['grants_created']++;
        }
        
        // Check if user already exists
        $existing_user = get_user_by('email', $pd_email);
        
        if ($existing_user) {
            $this->import_results['users_skipped']++;
        } else {
            // Create user
            $user_id = $this->create_user($pd_name, $pd_email, $user_role);
            
            if (is_wp_error($user_id)) {
                $this->import_results['errors'][] = "Row " . ($index + 1) . ": Failed to create user - " . $user_id->get_error_message();
            } else {
                $this->import_results['users_created']++;
            }
        }
    }
    
    /**
     * Convert date from M/D/YYYY to YYYY-MM-DD
     */
    private function convert_date($date_string) {
        $date_string = trim($date_string);
        
        if (empty($date_string)) {
            return '';
        }
        
        $date = DateTime::createFromFormat('n/j/Y', $date_string);
        
        if ($date) {
            return $date->format('Y-m-d');
        }
        
        return $date_string; // Return original if conversion fails
    }
    
    /**
     * Parse phone number and extract extension
     */
    private function parse_phone_number($phone_string) {
        $phone_string = trim($phone_string);
        
        $result = [
            'phone' => '',
            'extension' => ''
        ];
        
        // Check for extension
        if (preg_match('/^(.+?)\s+Ext:?\s*(.+)$/i', $phone_string, $matches)) {
            $phone_string = trim($matches[1]);
            $result['extension'] = trim($matches[2]);
        }
        
        // Extract digits only
        $digits = preg_replace('/\D/', '', $phone_string);
        
        // Format as (###) ###-####
        if (strlen($digits) === 10) {
            $result['phone'] = '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
        } else {
            // Return original if not 10 digits
            $result['phone'] = $phone_string;
        }
        
        return $result;
    }
    
    /**
     * Map taxonomy slug to user role
     */
    private function map_taxonomy_slug_to_role($taxonomy_slug) {
        $mapping = [
            'bhwet-para' => 'bhwet-para-user',
            'bhwet-pro' => 'bhwet-pro-user',
            'bhwet-social' => 'bhwet-social-user',
            'gpe' => 'gpe-user',
            'istp' => 'istp-user',
            'oifsp' => 'oifsp-user',
            'amf' => 'amf-user'
        ];
        
        return isset($mapping[$taxonomy_slug]) ? $mapping[$taxonomy_slug] : 'subscriber';
    }
    
    /**
     * Check if grant exists by grant number
     */
    private function get_grant_by_number($grant_number) {
        $args = [
            'post_type' => 'grant',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'grant_number',
                    'value' => $grant_number,
                    'compare' => '='
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        return false;
    }
    
    /**
     * Create grant post
     */
    private function create_grant_post($org_name, $grant_number, $city, $state, $start_date, $end_date, $pd_name, $pd_email, $phone_data, $taxonomy_slug) {
        // Create post
        $post_data = [
            'post_title' => $org_name,
            'post_type' => 'grant',
            'post_status' => 'publish'
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Set taxonomy term - using term slug
        $term = get_term_by('slug', $taxonomy_slug, 'group-program');
        if ($term && !is_wp_error($term)) {
            $result = wp_set_object_terms($post_id, (int)$term->term_id, 'group-program', false);
            if (is_wp_error($result)) {
                // Log error but continue with post creation
                error_log("Grant Importer: Failed to set taxonomy term '{$taxonomy_slug}' for post {$post_id}: " . $result->get_error_message());
            }
        } else {
            // Log that term wasn't found
            error_log("Grant Importer: Taxonomy term '{$taxonomy_slug}' not found in 'group-program' taxonomy for post {$post_id}");
        }
        
        // Set ACF fields
        update_field('grant_number', $grant_number, $post_id);
        update_field('city', $city, $post_id);
        update_field('state', $state, $post_id);
        update_field('grant_start_date', $start_date, $post_id);
        update_field('grant_end_date', $end_date, $post_id);
        update_field('contact_name', $pd_name, $post_id);
        update_field('contact_email', $pd_email, $post_id);
        update_field('contact_phone', $phone_data['phone'], $post_id);
        
        if (!empty($phone_data['extension'])) {
            update_field('contact_phone_extension', $phone_data['extension'], $post_id);
        }
        
        return $post_id;
    }
    
    /**
     * Create user
     */
    private function create_user($full_name, $email, $role) {
        // Split name into first and last
        $name_parts = $this->split_name($full_name);
        
        // Extract username from email (portion before @)
        $username = strstr($email, '@', true);
        
        // Prevent new user notification emails
        add_filter('wp_new_user_notification_email_admin', '__return_false');
        add_filter('wp_new_user_notification_email', '__return_false');
        
        // Create user data
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'first_name' => $name_parts['first_name'],
            'last_name' => $name_parts['last_name'],
            'role' => $role,
            'user_pass' => wp_generate_password(12, true, true)
        ];
        
        $user_id = wp_insert_user($user_data);
        
        return $user_id;
    }
    
    /**
     * Split full name into first and last name
     */
    private function split_name($full_name) {
        $full_name = trim($full_name);
        $parts = explode(' ', $full_name);
        
        $result = [
            'first_name' => '',
            'last_name' => ''
        ];
        
        if (count($parts) === 1) {
            $result['first_name'] = $parts[0];
        } elseif (count($parts) === 2) {
            $result['first_name'] = $parts[0];
            $result['last_name'] = $parts[1];
        } else {
            // Everything before last name goes into first name (handles middle names/initials)
            $result['last_name'] = array_pop($parts);
            $result['first_name'] = implode(' ', $parts);
        }
        
        return $result;
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get CSV data from transient
        $csv_filename = get_transient('grant_importer_csv_file');
        $csv_data = get_transient('grant_importer_csv_data');
        $import_results = get_transient('grant_importer_results');
        
        // Clear results after displaying
        if ($import_results && isset($_GET['import_complete'])) {
            delete_transient('grant_importer_results');
        }
        
        ?>
        <div class="wrap">
            <h1>Grant CSV Importer</h1>
            
            <?php settings_errors('grant_importer'); ?>
            
            <?php if ($import_results && isset($_GET['import_complete'])): ?>
                <div class="notice notice-success">
                    <h2>Import Complete</h2>
                    <ul>
                        <li><strong>Grants Created:</strong> <?php echo $import_results['grants_created']; ?></li>
                        <li><strong>Grants Skipped (already exist):</strong> <?php echo $import_results['grants_skipped']; ?></li>
                        <li><strong>Users Created:</strong> <?php echo $import_results['users_created']; ?></li>
                        <li><strong>Users Skipped (already exist):</strong> <?php echo $import_results['users_skipped']; ?></li>
                    </ul>
                    
                    <?php if (!empty($import_results['errors'])): ?>
                        <h3>Errors:</h3>
                        <ul>
                            <?php foreach ($import_results['errors'] as $error): ?>
                                <li style="color: red;"><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$csv_data): ?>
                <!-- Step 1: Upload CSV -->
                <div class="card" style="max-width: 800px;">
                    <h2>Step 1: Upload CSV File</h2>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('grant_importer_upload', 'grant_importer_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="group_program_term">Group Program</label>
                                </th>
                                <td>
                                    <select name="group_program_term" id="group_program_term" required style="min-width: 300px;">
                                        <option value="">-- Select Group Program --</option>
                                        <?php
                                        $terms = get_terms([
                                            'taxonomy' => 'group-program',
                                            'hide_empty' => false,
                                        ]);
                                        
                                        if (!is_wp_error($terms) && !empty($terms)):
                                            foreach ($terms as $term):
                                        ?>
                                            <option value="<?php echo esc_attr($term->slug); ?>">
                                                <?php echo esc_html($term->name); ?>
                                            </option>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                            <option value="" disabled>No terms found - please create group-program taxonomy terms</option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description">Select which Group Program these grants belong to. All imported grants will be assigned to this program.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="csv_file">CSV File</label>
                                </th>
                                <td>
                                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                                    <p class="description">Upload a CSV file with grant data.</p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="upload_csv" class="button button-primary" value="Upload and Preview">
                        </p>
                    </form>
                </div>
                
                <!-- Mapping Reference -->
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2>Field Mapping Reference</h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>CSV Column</th>
                                <th>Maps To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><em>(Selected from dropdown)</em></td>
                                <td>Group Program Taxonomy + User Role</td>
                            </tr>
                            <tr>
                                <td>Organization Name</td>
                                <td>Grant Post Title</td>
                            </tr>
                            <tr>
                                <td>Grant Number</td>
                                <td>ACF Field: grant_number</td>
                            </tr>
                            <tr>
                                <td>City</td>
                                <td>ACF Field: city</td>
                            </tr>
                            <tr>
                                <td>State</td>
                                <td>ACF Field: state</td>
                            </tr>
                            <tr>
                                <td>Current Project Period Start Date</td>
                                <td>ACF Field: grant_start_date</td>
                            </tr>
                            <tr>
                                <td>Current Project Period End Date</td>
                                <td>ACF Field: grant_end_date</td>
                            </tr>
                            <tr>
                                <td>Project Director - Name</td>
                                <td>ACF Field: contact_name + User First/Last Name</td>
                            </tr>
                            <tr>
                                <td>Project Director - Email</td>
                                <td>ACF Field: contact_email + User Login/Email</td>
                            </tr>
                            <tr>
                                <td>Project Director - Phone</td>
                                <td>ACF Fields: contact_phone + contact_phone_extension</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            
            <?php else: ?>
                <!-- Step 2: Review and Import -->
                <div class="card" style="max-width: 1200px;">
                    <h2>Step 2: Review Data and Execute Import</h2>
                    
                    <?php 
                    $selected_term_slug = get_transient('grant_importer_group_program');
                    $selected_term = get_term_by('slug', $selected_term_slug, 'group-program');
                    ?>
                    
                    <p><strong>File:</strong> <?php echo esc_html($csv_filename); ?></p>
                    <p><strong>Group Program:</strong> <?php echo $selected_term ? esc_html($selected_term->name) : esc_html($selected_term_slug); ?></p>
                    <p><strong>Total Rows:</strong> <?php echo count($csv_data); ?></p>
                    
                    <h3>Preview (First 5 Rows)</h3>
                    <div style="overflow-x: auto;">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Organization</th>
                                    <th>Grant Number</th>
                                    <th>City, State</th>
                                    <th>Project Director</th>
                                    <th>Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $preview_data = array_slice($csv_data, 0, 5);
                                foreach ($preview_data as $index => $row): 
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo esc_html($row['Organization Name']); ?></td>
                                    <td><?php echo esc_html($row['Grant Number']); ?></td>
                                    <td><?php echo esc_html($row['City'] . ', ' . $row['State']); ?></td>
                                    <td><?php echo esc_html($row['Project Director - Name']); ?></td>
                                    <td><?php echo esc_html($row['Project Director - Email']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="post" style="margin-top: 20px;">
                        <?php wp_nonce_field('grant_importer_upload', 'grant_importer_nonce'); ?>
                        <input type="hidden" name="csv_filename" value="<?php echo esc_attr($csv_filename); ?>">
                        
                        <h3>Import Options</h3>
                        <p>Choose how many rows to import:</p>
                        
                        <p>
                            <button type="submit" name="execute_import" value="1" class="button" 
                                    onclick="return confirm('Import 1 row for testing?');">
                                Import 1 Row (Test)
                            </button>
                            
                            <button type="submit" name="execute_import" value="5" class="button" 
                                    onclick="return confirm('Import 5 rows for testing?');">
                                Import 5 Rows (Test)
                            </button>
                            
                            <button type="submit" name="execute_import" value="all" class="button button-primary" 
                                    onclick="return confirm('Import all <?php echo count($csv_data); ?> rows? This action will create grants and users.');">
                                Import All Rows (<?php echo count($csv_data); ?>)
                            </button>
                        </p>
                        
                        <input type="hidden" name="import_count" value="" id="import_count_field">
                    </form>
                    
                    <form method="post" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                        <?php wp_nonce_field('grant_importer_upload', 'grant_importer_nonce'); ?>
                        <p>
                            <button type="submit" name="clear_import" class="button button-secondary" 
                                    onclick="return confirm('Clear this CSV and start over? This will not delete any imported data, only reset the import tool.');">
                                Clear and Start Over
                            </button>
                            <span class="description" style="margin-left: 10px;">Reset the import process to upload a new CSV file</span>
                        </p>
                    </form>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('button[name="execute_import"]').on('click', function() {
                            $('#import_count_field').val($(this).val());
                        });
                    });
                    </script>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Initialize plugin
new Grant_CSV_Importer();
