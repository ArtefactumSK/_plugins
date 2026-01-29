<?php
/**
 * Plugin Name: Artefactum Licence API Extended
 * Description: REST API pre kontrolu licencií + wpDataTables integrácia
 * Version: 4.1 - Fixed Responsive Tables
 */

// Bezpečnostný token (zmeň na vlastný náhodný string)
define('ARTEFACTUM_API_SECRET', 'ART-MH8T-R13N-2938-O9JA-7RD9');

// Database config
global $wpdb;
$wpdb->licences = 'wp_magic2_artefactum_licences';
$wpdb->licence_logs = 'wp_magic2_artefactum_licence_logs';
$wpdb->clients = 'wp_magic2_artefactum_clients';
$wpdb->api_logs = 'wp_magic2_artefactum_api_logs';

/**
 * Registrácia REST API endpointu
 */
add_action('rest_api_init', function() {
    // Existujúci endpoint
    register_rest_route('artefactum/v1', '/licence-check', [
        'methods' => 'POST',
        'callback' => 'artefactum_api_check_licence',
        'permission_callback' => '__return_true'
    ]);

    // Endpoint: Kontrola licencie podľa UID
    register_rest_route('artefactum/v1', '/license-status', [
        'methods' => 'GET',
        'callback' => 'artefactum_api_license_status_by_uid',
        'permission_callback' => '__return_true',
        'args' => [
            'uid' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return preg_match('/^ART-\d{6}$/', $param);
                }
            ]
        ]
    ]);

    // Endpoint: Informácie o klientovi
    register_rest_route('artefactum/v1', '/client-info', [
        'methods' => 'GET',
        'callback' => 'artefactum_api_client_info',
        'permission_callback' => '__return_true',
        'args' => [
            'uid' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return preg_match('/^ART-\d{6}$/', $param);
                }
            ]
        ]
    ]);

    // Nový endpoint pre všetky licencie
    register_rest_route('artefactum/v1', '/all-licences', [
        'methods' => 'GET',
        'callback' => 'artefactum_api_all_licences',
        'permission_callback' => function() {
            $current_user = wp_get_current_user();
            return $current_user->user_email === 'admin@artefactum.sk';
        }
    ]);
    
    // Extended Statistics API
    register_rest_route('artefactum/v1', '/extended-stats', [
        'methods'  => 'GET',
        'callback' => 'artefactum_api_extended_stats',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

// --- PRIPOJENIE JAVASCRIPTU DO ADMINISTRÁCIE --- //
function artefactum_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'artefactum') === false) {
        return;
    }

    wp_enqueue_script(
        'artefactum-admin-js',
        plugin_dir_url(__FILE__) . 'artefactum-admin.js',
        array('jquery'),
        '1.0',
        true
    );

    wp_localize_script(
        'artefactum-admin-js',
        'artefactum_admin',
        array(
            'api_url' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('artefactum_admin_nonce')
        )
    );
}
add_action('admin_enqueue_scripts', 'artefactum_admin_enqueue_scripts');

/**
 * Callback pre endpoint /all-licences
 */
function artefactum_api_all_licences($request) {
    global $wpdb;

    $token = $request->get_header('Authorization');
    $token = str_replace('Bearer ', '', $token);
    $expected_token = hash_hmac('sha256', 'admin_access', ARTEFACTUM_API_SECRET);
    if (!hash_equals($expected_token, $token)) {
        return new WP_Error('invalid_token', 'Neplatný token', ['status' => 403]);
    }

    $licences = $wpdb->get_results("SELECT * FROM {$wpdb->licences} ORDER BY created_at DESC");

    $response = [];
    foreach ($licences as $licence) {
        $response[] = artefactum_calculate_licence_status($licence);
    }

    return rest_ensure_response($response);
}

/**
 * API endpoint: Získanie stavu licencie podľa customer_uid
 */
function artefactum_api_license_status_by_uid($request) {
    global $wpdb;
    
    $uid = sanitize_text_field($request->get_param('uid'));
    
    $client = $wpdb->get_row($wpdb->prepare(
        "SELECT domain FROM {$wpdb->clients} WHERE customer_uid = %s LIMIT 1",
        $uid
    ));
    
    if (!$client) {
        artefactum_log_api_call('license-status', $uid, 'client_not_found');
        return new WP_Error('no_client', 'Klient s týmto UID nebol nájdený', ['status' => 404]);
    }
    
    $stored_domain = $client->domain;
    $parts = explode('.', $stored_domain);
    $root_domain = count($parts) >= 2 
        ? implode('.', array_slice($parts, -2)) 
        : $stored_domain;
    
    $primary_domain = $root_domain;
    
    $licences = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->licences}
         WHERE (
            domain = %s 
            OR domain = %s 
            OR domain LIKE %s
         )
         AND status = 'active'
         ORDER BY 
            CASE 
                WHEN domain = %s THEN 1
                WHEN domain LIKE %s THEN 2
                ELSE 3
            END,
            expiry_date DESC",
        $primary_domain,
        '*.' . $root_domain,
        '%.' . $root_domain,
        $primary_domain,
        '%.' . $root_domain
    ));
    
    if (empty($licences)) {
        artefactum_log_api_call('license-status', $uid, 'no_licences_found');
        return new WP_Error('no_licences', 'Žiadne licencie pre tento UID', ['status' => 404]);
    }
    
    $response = [];
    
    foreach ($licences as $licence) {
        $status = artefactum_calculate_licence_status($licence);
        $status['customer_uid'] = $uid;
        $status['domain'] = $licence->domain;
        $status['is_primary'] = (strtolower($licence->domain) === strtolower($primary_domain));
        
        $response[] = $status;
    }
	if (count($response)==1){$sk_licences ='licencia';}
	else if (count($response)>1 && count($response)<5){$sk_licences ='licencie';}
	else {$sk_licences ='licencií';}
    
    artefactum_log_api_call('license-status', $uid, 'success', count($response) . ' '.$sk_licences);
    
    return rest_ensure_response($response);
}

/**
 * API endpoint: Získanie informácií o klientovi podľa UID
 */
function artefactum_api_client_info($request) {
    global $wpdb;
    
    $uid = sanitize_text_field($request->get_param('uid'));
    
    $client = $wpdb->get_row($wpdb->prepare(
        "SELECT customer_uid, company_name, domain, account_type, created_at
         FROM {$wpdb->clients}
         WHERE customer_uid = %s",
        $uid
    ));
    
    if (!$client) {
        artefactum_log_api_call('client-info', $uid, 'not_found');
        return new WP_Error('client_not_found', 'Klient nebol nájdený', ['status' => 404]);
    }
    
    $emails = $wpdb->get_results($wpdb->prepare(
        "SELECT email, role, is_primary
         FROM {$wpdb->prefix}artefactum_clients_emails
         WHERE customer_uid = %s",
        $uid
    ));
    
    $licence_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
         FROM {$wpdb->licences} l
         INNER JOIN {$wpdb->clients} c ON l.domain = c.domain
         WHERE c.customer_uid = %s AND l.status = 'active'",
        $uid
    ));
    
    $response = [
        'customer_uid' => $client->customer_uid,
        'company_name' => $client->company_name,
        'domain' => $client->domain,
        'account_type' => $client->account_type,
        'emails' => $emails,
        'active_licences' => (int) $licence_count,
        'member_since' => $client->created_at
    ];
    
    artefactum_log_api_call('client-info', $uid, 'success');
    return rest_ensure_response($response);
}

/**
 * API callback funkcia
 */
function artefactum_api_check_licence($request) {
    global $wpdb;
    
    $domain = sanitize_text_field($request->get_param('domain'));
    $token = sanitize_text_field($request->get_param('token'));
    $admin_email = sanitize_email($request->get_param('admin_email'));
    $filter_by_email = sanitize_email($request->get_param('filter_by_email'));
    
    if (empty($domain) || empty($token)) {
        return new WP_Error('missing_params', 'Chýbajúce parametre', ['status' => 400]);
    }
    
    $expected_token = hash_hmac('sha256', $domain, ARTEFACTUM_API_SECRET);
    if (!hash_equals($expected_token, $token)) {
        artefactum_log_check($domain, 'invalid_token', $request->get_header('X-Forwarded-For') ?: $_SERVER['REMOTE_ADDR']);
        return new WP_Error('invalid_token', 'Neplatný token', ['status' => 403]);
    }

    $domain = strtolower(preg_replace('/[^a-z0-9\.\-]/i', '', $domain));
    $parts = explode('.', $domain);
    $root_domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $domain;
    $wildcard_domain = '*.' . $root_domain;

    $is_subdomain = count($parts) > 2;

    if ($is_subdomain) {
        $query = "SELECT * FROM {$wpdb->licences} 
                  WHERE (domain = %s OR domain = %s OR domain = %s) 
                  AND status = 'active' 
                  ORDER BY 
                    CASE 
                      WHEN domain = %s THEN 1
                      WHEN domain = %s THEN 2
                      WHEN domain = %s THEN 3
                    END
                  LIMIT 1";
        
        $params = [
            $domain,
            $wildcard_domain,
            $root_domain,
            $domain,
            $wildcard_domain,
            $root_domain
        ];
        
        if ($filter_by_email) {
            $query .= " AND contact_email LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filter_by_email) . '%';
        }
        
        $licence = $wpdb->get_row($wpdb->prepare($query, $params));
        
        if (!$licence) {
            artefactum_log_check($domain, 'invalid_subdomain', $request->get_header('X-Forwarded-For') ?: $_SERVER['REMOTE_ADDR']);
            
            $error_messages = [[
                'message' => 'Subdoména ' . $domain . ' nie je licencovaná. Kontaktujte <a href="mailto:support@artefactum.sk">Artefactum Support</a>.',
                'priority' => 'critical',
                'source' => 'system'
            ]];
            
            return rest_ensure_response([
                'valid' => false,
                'status' => 'invalid_subdomain',
                'message' => 'Subdoména nie je licencovaná',
                'license_key' => null,
                'expiry_date' => null,
                'days_remaining' => null,
                'messages' => $error_messages
            ]);
        }
    } else {
        $query = "SELECT * FROM {$wpdb->licences} WHERE (domain = %s OR domain = %s) AND status = 'active' ORDER BY CASE WHEN domain = %s THEN 1 ELSE 2 END LIMIT 1";
        $params = [$domain, $root_domain, $domain];
        
        if ($filter_by_email) {
            $query .= " AND contact_email LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filter_by_email) . '%';
        }
        
        $licence = $wpdb->get_row($wpdb->prepare($query, $params));
    }

    if ($licence) {
        $wpdb->update(
            $wpdb->licences,
            [
                'last_seen' => current_time('mysql'),
                'check_count' => $licence->check_count + 1
            ],
            ['id' => $licence->id]
        );
    }

    $response = artefactum_calculate_licence_status($licence);

    $messages = [];

    $global_message = $wpdb->get_row("
        SELECT message, message_priority 
        FROM {$wpdb->licences} 
        WHERE domain = '*' AND message IS NOT NULL AND message != '' AND status = 'active'
        LIMIT 1
    ");

    if ($global_message && !empty($global_message->message)) {
        $messages[] = [
            'message' => $global_message->message,
            'priority' => $global_message->message_priority ?? 'info',
            'source' => 'global'
        ];
    }

    if ($licence && !empty($licence->message)) {
        $messages[] = [
            'message' => $licence->message,
            'priority' => $licence->message_priority ?? 'info',
            'source' => 'domain'
        ];
    }

    $response['messages'] = $messages;

    if (!empty($messages)) {
        $response['custom_message'] = $messages[0]['message'] ?? '';
        $response['message_priority'] = $messages[0]['priority'] ?? 'info';
    }

    artefactum_log_check($domain, $response['status'], $request->get_header('X-Forwarded-For') ?: $_SERVER['REMOTE_ADDR']);

    artefactum_log_api_call(
        'licence-check',
        $domain,
        $response['status'],
        'Token valid: ' . ($token ? 'yes' : 'no')
    );

    return rest_ensure_response($response);
}

/**
 * Výpočet statusu licencie
 */
function artefactum_calculate_licence_status($licence) {
    if (!$licence) {
        return [
            'valid' => false,
            'status' => 'not_found',
            'message' => 'Licencia nebola nájdená',
            'license_key' => null,
            'expiry_date' => null,
            'days_remaining' => null,
            'customer_uid' => null 
        ];
    }
    
    $GRACE_DAYS = 28;
    $PRE_WARNING_DAYS = 30;
    
    if (empty($licence->expiry_date)) {
        return [
            'valid' => true,
            'status' => 'active',
            'message' => 'Neobmedzena licencia',
            'license_key' => $licence->license_key,
            'expiry_date' => null,
            'days_remaining' => null,
            'grace_period' => false,
            'pre_warning' => false,
            'customer_uid' => $licence->customer_uid ?? null
        ];
    }
    
    $today = new DateTime('now', new DateTimeZone('Europe/Bratislava'));
    $expiry = DateTime::createFromFormat('Y-m-d', $licence->expiry_date, new DateTimeZone('Europe/Bratislava'));
    $diff = $today->diff($expiry);
    $days_diff = $diff->days * ($today > $expiry ? -1 : 1);
    
    if ($days_diff < -$GRACE_DAYS) {
        return [
            'valid' => false,
            'status' => 'expired',
            'message' => 'Licencia expirovala',
            'license_key' => $licence->license_key,
            'expiry_date' => $licence->expiry_date,
            'days_remaining' => 0,
            'grace_period' => false,
            'pre_warning' => false,
            'customer_uid' => $licence->customer_uid ?? null
        ];
    }
    
    if ($days_diff < 0) {
        $grace_days_left = $GRACE_DAYS + $days_diff;
        return [
            'valid' => true,
            'status' => 'grace',
            'message' => "Grace period: zostáva {$grace_days_left} dní",
            'license_key' => $licence->license_key,
            'expiry_date' => $licence->expiry_date,
            'days_remaining' => $grace_days_left,
            'grace_period' => true,
            'pre_warning' => false,
            'customer_uid' => $licence->customer_uid ?? null
        ];
    }
    
    if ($days_diff <= $PRE_WARNING_DAYS) {
        return [
            'valid' => true,
            'status' => 'warning',
            'message' => "Licencia vyprší o {$days_diff} dní",
            'license_key' => $licence->license_key,
            'expiry_date' => $licence->expiry_date,
            'days_remaining' => $days_diff,
            'grace_period' => false,
            'pre_warning' => true,
            'customer_uid' => $licence->customer_uid ?? null
        ];
    }
    
    return [
        'valid' => true,
        'status' => 'active',
        'message' => 'Licencia je platná',
        'license_key' => $licence->license_key,
        'expiry_date' => $licence->expiry_date,
        'days_remaining' => $days_diff,
        'grace_period' => false,
        'pre_warning' => false,
        'customer_uid' => $licence->customer_uid ?? null
    ];
}

/**
 * Logovanie kontroly
 */
function artefactum_log_check($domain, $status, $ip) {
    global $wpdb;
    
    $wpdb->insert(
        $wpdb->licence_logs,
        [
            'domain' => $domain,
            'action' => 'check_' . $status,
            'ip_address' => $ip,
            'created_at' => current_time('mysql')
        ]
    );
}

/**
 * Logovanie API volaní do súboru a databázy
 */
function artefactum_log_api_call($endpoint, $identifier, $result, $details = '') {
    global $wpdb;
    
    $log_file = WP_CONTENT_DIR . '/artefactum-api.log';
    $log_entry = sprintf(
        "[%s] %s | %s | %s | IP: %s | Result: %s | Details: %s\n",
        current_time('Y-m-d H:i:s'),
        $endpoint,
        $identifier,
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $result,
        $details
    );
    
    if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
        rename($log_file, $log_file . '.' . date('Y-m-d-His') . '.old');
    }
    
    if (!file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
    }
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    $wpdb->insert(
        $wpdb->api_logs,
        [
            'endpoint' => $endpoint,
            'identifier' => $identifier,
            'result' => $result,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'details' => $details,
            'created_at' => current_time('mysql')
        ]
    );
}

/**
 * Admin menu
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Artefactum Licencie',
        'LICENCIE',
        'manage_options',
        'artefactum-licences',
        'artefactum_admin_page',
        'dashicons-admin-network',
        100
    );
    
    add_submenu_page(
        'artefactum-licences',
        'Logy',
        'Logy',
        'manage_options',
        'artefactum-logs',
        'artefactum_logs_page'
    );
});

/**
 * Admin stránka - zoznam licencií + formulár
 */
function artefactum_admin_page() {
    global $wpdb;
    
    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $edit_licence = null;
    
    if ($edit_id > 0) {
        $edit_licence = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->licences} WHERE id = %d",
            $edit_id
        ));
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('artefactum_licence')) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'delete' && !empty($_POST['domain'])) {
            $domain = sanitize_text_field($_POST['domain']);
            $wpdb->delete($wpdb->licences, ['domain' => $domain]);
            echo '<div class="notice notice-success is-dismissible"><p>✔ Licencia zmazaná!</p></div>';
        }
        
        if ($action === 'extend' && !empty($_POST['id'])) {
            $id = intval($_POST['id']);
            $new_expiry = !empty($_POST['new_expiry_date']) ? sanitize_text_field($_POST['new_expiry_date']) : null;
            
            if ($new_expiry) {
                $wpdb->update(
                    $wpdb->licences,
                    ['expiry_date' => $new_expiry, 'status' => 'active'],
                    ['id' => $id]
                );
                
                echo '<div class="notice notice-success is-dismissible"><p>✔ Licencia predĺžená do ' . date('d.m.Y', strtotime($new_expiry)) . '</p></div>';
            }
        }
        
        if ($action === 'toggle_status' && !empty($_POST['id'])) {
            $id = intval($_POST['id']);
            $lic = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->licences} WHERE id = %d", $id));
            
            if ($lic) {
                $new_status = ($lic->status === 'active') ? 'suspended' : 'active';
                $wpdb->update($wpdb->licences, ['status' => $new_status], ['id' => $id]);
                
                echo '<div class="notice notice-success is-dismissible"><p>✔ Status zmenený na: ' . strtoupper($new_status) . '</p></div>';
            }
        }
        
        if ($action === 'save') {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            $domain = strtolower(preg_replace('/[^a-z0-9\.\-\*]/i', '', $_POST['domain'] ?? ''));
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');

            if (!empty($license_key)) {
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) 
                         FROM {$wpdb->licences} 
                         WHERE license_key = %s
                         " . ($id > 0 ? "AND id != %d" : ""),
                        $id > 0 ? [$license_key, $id] : [$license_key]
                    )
                );

                if ($exists > 0) {
                    $license_key = artefactum_generate_unique_license_key($wpdb);
                }

                $_POST['license_key'] = $license_key;
            }
            
            $emails_raw = sanitize_text_field($_POST['contact_email'] ?? '');
            $emails_array = array_map('trim', explode(',', $emails_raw));
            $emails_array = array_filter($emails_array, function($e) {
                return filter_var($e, FILTER_VALIDATE_EMAIL);
            });
            $contact_email = implode(', ', $emails_array);
            
            $data = [
                'domain' => $domain,
                'license_key' => $license_key,
                'client_name' => sanitize_text_field($_POST['client_name'] ?? ''),
                'contact_email' => $contact_email,
                'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
                'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
                'message' => wp_kses_post($_POST['message'] ?? ''),
                'message_priority' => sanitize_text_field($_POST['message_priority'] ?? 'info'),
                'status' => sanitize_text_field($_POST['status'] ?? 'active'),
                'updated_at' => current_time('mysql')
            ];
            
            if ($id > 0) {
                $wpdb->update($wpdb->licences, $data, ['id' => $id]);
                echo '<div class="notice notice-success is-dismissible"><p>✔ Licencia aktualizovaná!</p></div>';
                $edit_licence = null;
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($wpdb->licences, $data);
                echo '<div class="notice notice-success is-dismissible"><p>✔ Licencia vytvorená!</p></div>';
            }
        }
    }
    
    $licences = $wpdb->get_results("SELECT * FROM {$wpdb->licences} ORDER BY created_at DESC");
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-admin-network" style="font-size:28px;vertical-align:middle;"></span>
            Artefactum Licencie
        </h1>
        
        <?php if ($edit_licence): ?>
            <a href="<?php echo admin_url('admin.php?page=artefactum-licences'); ?>" class="page-title-action">+ Pridať novú</a>
        <?php endif; ?>
        
        <hr class="wp-header-end">
        
        <!-- FORMULÁR -->
        <div style="background:#fff; padding:20px; margin:20px 0; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04);">
            <h2><?php echo $edit_licence ? '✏️ Upraviť licenciu' : '➕ Pridať novú licenciu'; ?></h2>
            
            <form method="POST">
                <?php wp_nonce_field('artefactum_licence'); ?>
                <input type="hidden" name="action" value="save">
                <?php if ($edit_licence): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_licence->id; ?>">
                <?php endif; ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="domain">Doména <span style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" name="domain" id="domain" class="regular-text" required 
                                   value="<?php echo $edit_licence ? esc_attr($edit_licence->domain) : ''; ?>"
                                   placeholder="example.com alebo *.example.com">
                            <p class="description">Pre wildcard použite <code>*.domena.sk</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_key">Licenčný kľúč</label></th>
                        <td>
                            <input type="text" name="license_key" id="license_key" class="regular-text"
                                   value="<?php echo $edit_licence ? esc_attr($edit_licence->license_key) : ''; ?>"
                                   placeholder="">
                            <p class="description">Automaticky vygeneruje, ak je pole prázdne.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_name">Meno klienta</label></th>
                        <td>
                            <input type="text" name="client_name" id="client_name" class="regular-text"
                                   value="<?php echo $edit_licence ? esc_attr($edit_licence->client_name) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="contact_email">Kontaktné email(y)</label></th>
                        <td>
                            <input type="text" name="contact_email" id="contact_email" class="regular-text"
                                   value="<?php echo $edit_licence ? esc_attr($edit_licence->contact_email) : ''; ?>"
                                   placeholder="email1@example.com, email2@example.com">
                            <p class="description">Viacero emailov oddeľte čiarkou</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expiry_date">Dátum expirácie</label></th>
                        <td>
                            <input type="date" name="expiry_date" id="expiry_date"
                                   value="<?php echo $edit_licence ? esc_attr($edit_licence->expiry_date) : ''; ?>">
                            <p class="description">Nechajte prázdne pre neobmedzenu licenciu (∞)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php echo ($edit_licence && $edit_licence->status === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="suspended" <?php echo ($edit_licence && $edit_licence->status === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="expired" <?php echo ($edit_licence && $edit_licence->status === 'expired') ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="notes">Poznámky</label></th>
                        <td>
                            <textarea name="notes" id="notes" rows="3" class="large-text"><?php echo $edit_licence ? esc_textarea($edit_licence->notes) : ''; ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="message">Správa pre klienta</label></th>
                        <td>
                            <textarea name="message" id="message" rows="3" class="large-text" 
                                      placeholder="Dôležitá správa, ktorá sa zobrazí vo WordPress admin widgete klienta..."><?php echo $edit_licence ? esc_textarea($edit_licence->message) : ''; ?></textarea>
                            <p class="description">
                                💡 <strong>Tip:</strong> Pre globálnu správu (všetky domény) vytvor licenciu s doménou <code>*</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="message_priority">Priorita správy</label></th>
                        <td>
                            <select name="message_priority" id="message_priority">
                                <option value="info" <?php echo ($edit_licence && $edit_licence->message_priority === 'info') ? 'selected' : ''; ?>>
                                    ℹ️ Info (modrá)
                                </option>
                                <option value="warning" <?php echo ($edit_licence && $edit_licence->message_priority === 'warning') ? 'selected' : ''; ?>>
                                    ⚠️ Warning (oranžová)
                                </option>
                                <option value="critical" <?php echo ($edit_licence && $edit_licence->message_priority === 'critical') ? 'selected' : ''; ?>>
                                    🚨 Critical (červená)
                                </option>
                            </select>
                            <p class="description">Farba pozadia správy v klientskom widgete</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $edit_licence ? '💾 Uložiť zmeny' : '➕ Pridať licenciu'; ?>
                    </button>
                    <?php if ($edit_licence): ?>
                        <a href="<?php echo admin_url('admin.php?page=artefactum-licences'); ?>" class="button">Zrušiť</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        
        <!-- TABUĽKA LICENCIÍ -->
        <h2>Existujúce licencie (<?php echo count($licences); ?>)</h2>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:12%;">Doména</th>
                    <th style="width:10%;">Licenčný kľúč</th>
                    <th style="width:10%;">Klient</th>
                    <th style="width:10%;">Email(y)</th>
                    <th style="width:7%;text-align:center;">Expirácia</th>
                    <th style="width:5%;text-align:center;">Status</th>
                    <th style="width:9%;">Last Seen</th>
                    <th style="width:10%;">Poznámka</th>
                    <th style="width:10%;">Správa</th>
                    <th style="width:15%;text-align:center;">Akcie</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licences)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:40px;color:#999;">Žiadne licencie</td></tr>
                <?php else: ?>
                    <?php foreach ($licences as $lic): ?>
                        <?php
                        $status_colors = [
                            'active' => '#10b981',
                            'suspended' => '#FF6600',
                            'expired' => '#ef4444'
                        ];
                        $color = $status_colors[$lic->status] ?? '#FF6600';
                        
                        $expiry_display = $lic->expiry_date 
                            ? date('d.m.Y', strtotime($lic->expiry_date))
                            : '<em style="color:#10b981;">∞</em>';
                        
                        $last_seen = $lic->last_seen 
                            ? date('d.m.Y H:i', strtotime($lic->last_seen))
                            : '<em style="color:#999;">Nikdy</em>';
                        
                        $domain_display = strpos($lic->domain, '*') !== false 
                            ? '<span style="color:#605A5C;font-weight:bold;">🌐 ' . esc_html($lic->domain) . '</span>'
                            : '<strong>' . esc_html($lic->domain) . '</strong>';
                        
                        $emails_display = strlen($lic->contact_email) > 25 
                            ? substr($lic->contact_email, 0, 22) . '...'
                            : $lic->contact_email;
                        
                        $notes_display = $lic->notes 
                            ? (strlen($lic->notes) > 50 ? substr($lic->notes, 0, 47) . '...' : $lic->notes)
                            : '-';
                        
                        $message_display = $lic->message 
                            ? (strlen($lic->message) > 50 ? substr($lic->message, 0, 47) . '...' : $lic->message)
                            : '-';
                        
                        $priority_icons = [
                            'info' => '💬',
                            'warning' => '⚠️',
                            'critical' => '🚨'
                        ];
                        $priority_colors = [
                            'info' => '#3b82f6',
                            'warning' => '#605A5C',
                            'critical' => '#ef4444'
                        ];
                        $message_icon = $lic->message ? ($priority_icons[$lic->message_priority] ?? '💬') : '';
                        $message_color = $priority_colors[$lic->message_priority] ?? '#666';
                        
                        $default_extend_date = $lic->expiry_date 
                            ? date('Y-m-d', strtotime($lic->expiry_date . ' +1 year'))
                            : date('Y-m-d', strtotime('+1 year'));
                        ?>
                        <tr>
                            <td><?php echo $domain_display; ?></td>
                            <td><code style="font-size:10px;"><?php echo esc_html($lic->license_key); ?></code></td>
                            <td><?php echo esc_html($lic->client_name ?: '-'); ?></td>
                            <td title="<?php echo esc_attr($lic->contact_email); ?>">
                                <small><?php echo esc_html($emails_display ?: '-'); ?></small>
                            </td>
                            <td style="text-align:center;"><?php echo $expiry_display; ?></td>
                            <td style="text-align:center;">
                                <span style="color:<?php echo $color; ?>;font-weight:bold;font-size:10px;">
                                    <?php echo strtoupper($lic->status); ?>
                                </span>
                            </td>
                            <td><small style="font-size:11px;"><?php echo $last_seen; ?></small></td>
                            <td title="<?php echo esc_attr($lic->notes); ?>">
                                <small style="color:#666;"><?php echo esc_html($notes_display); ?></small>
                            </td>
                            <td title="<?php echo esc_attr($lic->message); ?>">
                                <?php if ($lic->message): ?>
                                    <small style="color:<?php echo $message_color; ?>;">
                                        <?php echo $message_icon; ?> <?php echo esc_html($message_display); ?>
                                    </small>
                                <?php else: ?>
                                    <small style="color:#999;">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:2px;align-items:center;justify-content:center;">
                                    <a href="<?php echo admin_url('admin.php?page=artefactum-licences&edit=' . $lic->id); ?>" 
                                       class="button button-small" 
                                       title="Upraviť">
                                        ✏️
                                    </a>
                                    
                                    <button type="button" 
                                            class="button button-small extend-btn" 
                                            data-id="<?php echo $lic->id; ?>"
                                            data-domain="<?php echo esc_attr($lic->domain); ?>"
                                            data-current="<?php echo esc_attr($lic->expiry_date); ?>"
                                            data-default="<?php echo esc_attr($default_extend_date); ?>"
                                            title="Predĺžiť licenciu">
                                        📅
                                    </button>
                                    
                                    <form method="POST" style="display:inline;">
                                        <?php wp_nonce_field('artefactum_licence'); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?php echo $lic->id; ?>">
                                        <button type="submit" class="button button-small" 
                                                title="<?php echo $lic->status === 'active' ? 'Pozastaviť' : 'Aktivovať'; ?>">
                                            <?php echo $lic->status === 'active' ? '⏸️' : '▶️'; ?>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline;">
                                        <?php wp_nonce_field('artefactum_licence'); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="domain" value="<?php echo esc_attr($lic->domain); ?>">
                                        <button type="submit" class="button button-small" 
                                                onclick="return confirm('Naozaj zmazať licenciu pre <?php echo esc_js($lic->domain); ?>?')"
                                                title="Zmazať">
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- MODAL pre predĺženie licencie -->
        <div id="extend-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:100000; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:30px; border-radius:8px; max-width:500px; width:90%; box-shadow:0 5px 25px rgba(0,0,0,0.3);">
                <h2 style="margin-top:0;">📅 Predĺžiť licenciu</h2>
                
                <form method="POST" id="extend-form">
                    <?php wp_nonce_field('artefactum_licence'); ?>
                    <input type="hidden" name="action" value="extend">
                    <input type="hidden" name="id" id="extend-id">
                    
                    <p><strong>Doména:</strong> <span id="extend-domain"></span></p>
                    <p><strong>Súčasná expirácia:</strong> <span id="extend-current"></span></p>
                    
                    <p style="margin-top:20px;">
                        <label for="new_expiry_date"><strong>Nový dátum expirácie:</strong></label><br>
                        <input type="date" 
                               name="new_expiry_date" 
                               id="new_expiry_date" 
                               required 
                               style="width:100%; padding:8px; font-size:14px; border:1px solid #ddd; border-radius:4px; margin-top:5px;">
                    </p>
                    
                    <p style="margin-top:25px; text-align:right;">
                        <button type="button" class="button" id="cancel-extend">Zrušiť</button>
                        <button type="submit" class="button button-primary" style="margin-left:10px;">💾 Uložiť</button>
                    </p>
                </form>
            </div>
        </div>
        
        <style>
        .wp-list-table th { font-weight: 600; }
        .button-small { padding: 2px 8px !important; font-size: 18px !important; line-height: 1 !important; }
        #extend-modal { display: none; }
        #extend-modal.active { display: flex !important; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.extend-btn').on('click', function() {
                var id = $(this).data('id');
                var domain = $(this).data('domain');
                var current = $(this).data('current');
                var defaultDate = $(this).data('default');
                
                $('#extend-id').val(id);
                $('#extend-domain').text(domain);
                $('#extend-current').text(current ? new Date(current).toLocaleDateString('sk-SK') : 'Neobmedzená');
                $('#new_expiry_date').val(defaultDate);
                
                $('#extend-modal').addClass('active');
            });
            
            $('#cancel-extend, #extend-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#extend-modal').removeClass('active');
                }
            });
            
            $('#extend-modal > div').on('click', function(e) {
                e.stopPropagation();
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Logy stránka
 */
function artefactum_logs_page() {
    global $wpdb;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_logs' && check_admin_referer('artefactum_delete_logs')) {
        $deleted = $wpdb->query("TRUNCATE TABLE {$wpdb->licence_logs}");
        echo '<div class="notice notice-success is-dismissible"><p>✔ Všetky logy boli vymazané!</p></div>';
    }
    
    $logs = $wpdb->get_results("
        SELECT * FROM {$wpdb->licence_logs} 
        ORDER BY created_at DESC 
        LIMIT 200
    ");
    
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licence_logs}");
    
    ?>
    <div class="wrap">
        <h1>📊 API Logy (posledných 200 z <?php echo number_format($total_logs); ?>)</h1>
        
        <div style="margin:20px 0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <p style="color:#666;">Celkový počet logov v databáze: <strong><?php echo number_format($total_logs); ?></strong></p>
            </div>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Naozaj vymazať VŠETKY logy? Táto akcia je nevratná!');">
                <?php wp_nonce_field('artefactum_delete_logs'); ?>
                <input type="hidden" name="action" value="delete_logs">
                <button type="submit" class="button button-secondary" style="background:#dc2626; color:#fff; border-color:#dc2626;">
                    🗑️ Vymazať všetky logy
                </button>
            </form>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:15%;">Čas</th>
                    <th style="width:25%;">Doména</th>
                    <th style="width:20%;">Akcia</th>
                    <th style="width:15%;">IP Adresa</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="4" style="text-align:center;padding:40px;color:#999;">Žiadne logy</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $action_colors = [
                            'check_active' => '#10b981',
                            'check_warning' => '#605A5C',
                            'check_grace' => '#ef4444',
                            'check_expired' => '#dc2626',
                            'check_invalid_token' => '#9ca3af',
                            'check_not_found' => '#FF6600',
                            'check_invalid_subdomain' => '#dc2626'
                        ];
                        $action_color = $action_colors[$log->action] ?? '#333';
                        ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i:s', strtotime($log->created_at)); ?></td>
                            <td><strong><?php echo esc_html($log->domain); ?></strong></td>
                            <td>
                                <code style="color:<?php echo $action_color; ?>;font-weight:bold;">
                                    <?php echo esc_html($log->action); ?>
                                </code>
                            </td>
                            <td><code><?php echo esc_html($log->ip_address); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <style>
        .button-secondary:hover {
            background: #991b1b !important;
            border-color: #991b1b !important;
        }
        </style>
    </div>
    <?php
}

// --- API PRIPOJENIE NA CENTRÁLNY SERVER ARTEFACTUM --- //
function artefactum_api_connect($endpoint, $params = []) {
    $secret = defined('ARTEFACTUM_API_SECRET') ? ARTEFACTUM_API_SECRET : '';
    $response = wp_remote_post('https://artefactum.sk/api/' . $endpoint, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($params),
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body ?: false;
}

// ============================================================================
// FRONTEND SHORTCODE [artefactum_licence_statistics]
// ============================================================================

add_shortcode('artefactum_licence_statistics', 'artefactum_licence_statistics_shortcode');

function artefactum_licence_statistics_shortcode($atts) {
    global $wpdb;

    $licences = $wpdb->get_results("SELECT * FROM {$wpdb->licences} ORDER BY created_at DESC");
    
    if (empty($licences)) {
        return '<div style="background:#FCF8F7; padding:20px; border-radius:6px; border-left:4px solid #f60;">
                    <p style="color:#92400e; margin:0;">ℹ️ Žiadne licencie neboli nájdené.</p>
                </div>';
    }

    $total = count($licences);
    $active = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active'");
    $expired = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='expired'");
    $suspended = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='suspended'");
    $expiring_7 = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
    $expiring_30 = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)");
    $expiring_60 = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)");
    $in_grace = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date < NOW() AND expiry_date > DATE_SUB(NOW(), INTERVAL 28 DAY)");
    $perpetual = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE expiry_date IS NULL");
    $wildcards = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE domain LIKE '*%'");
    $checks_7days = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licence_logs} WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    
    $expiring_list = $wpdb->get_results("
        SELECT domain, license_key, client_name, expiry_date, DATEDIFF(expiry_date, NOW()) as days_left
        FROM {$wpdb->licences} 
        WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
        ORDER BY expiry_date ASC
        LIMIT 10
    ");

    $top_domains = $wpdb->get_results("
        SELECT domain, check_count, last_seen 
        FROM {$wpdb->licences} 
        WHERE check_count > 0 
        ORDER BY check_count DESC 
        LIMIT 10
    ");

    $global_message = $wpdb->get_row("
        SELECT message, message_priority 
        FROM {$wpdb->licences} 
        WHERE domain = '*' AND message IS NOT NULL AND message != ''
        LIMIT 1
    ");

    $domain_messages = $wpdb->get_results("
        SELECT domain, message, message_priority 
        FROM {$wpdb->licences} 
        WHERE message IS NOT NULL AND message != '' AND domain != '*'
        ORDER BY updated_at DESC
        LIMIT 10
    ");

    $active_percent = $total > 0 ? round(($active / $total) * 100) : 0;
    $expired_percent = $total > 0 ? round(($expired / $total) * 100) : 0;

    // === VÝPOČET MESAČNÉHO ROZPADU ===
$monthly_breakdown = array_fill(1, 12, [
    'sk' => ['count' => 0, 'sum' => 0],
    'eu' => ['count' => 0, 'sum' => 0],
    'com' => ['count' => 0, 'sum' => 0],
    'ssl' => ['count' => 0, 'sum' => 0],
    'hosting' => ['count' => 0, 'sum' => 0]
]);

if (!empty($all_yearly_services)) {
    foreach ($all_yearly_services as $service) {
        $month = (int)$service->expiry_month;
        $price = (float)$service->cenasluzbyrok;
        $name = strtolower($service->nazovsluyby);
        
        // Evidencie domén
        if (strpos($name, 'evidencia') !== false) {
            if (preg_match('/\.sk\b/i', $name)) {
                $monthly_breakdown[$month]['sk']['count']++;
                $monthly_breakdown[$month]['sk']['sum'] += ($price - 16.50);
            } elseif (preg_match('/\.eu\b/i', $name)) {
                $monthly_breakdown[$month]['eu']['count']++;
                $monthly_breakdown[$month]['eu']['sum'] += ($price - 12);
            } elseif (preg_match('/\.com\b/i', $name)) {
                $monthly_breakdown[$month]['com']['count']++;
                $monthly_breakdown[$month]['com']['sum'] += ($price - 18);
            }
        }
        
        // SSL certifikáty
        if (strpos($name, 'basic ssl') !== false) {
            $monthly_breakdown[$month]['ssl']['count']++;
            $monthly_breakdown[$month]['ssl']['sum'] += $price;
        }
        
        // Hostingy
        if (strpos($name, 'hosting') !== false) {
            $monthly_breakdown[$month]['hosting']['count']++;
            $monthly_breakdown[$month]['hosting']['sum'] += $price;
        }
    }
}

    ob_start();
    ?>
    <div class="artefactum-licence-statistics" style="max-width:1200px; margin:40px auto; padding:20px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <h2 style="text-align:center; color:#f60; margin-bottom:30px;">
            📋 Artefactum Licenses
        </h2>

        <!-- Štatistické karty -->
        <div class="arte-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <div class="arte-stat-card active" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #3b82f6;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Celkom</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; margin: 5px 0; color:#3b82f6;"><?php echo $total; ?></div>
                <div class="arte-progress-bar" style="background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden; margin: 10px 0;">
                    <div class="arte-progress-fill" style="width:100%; background:#3b82f6; height:100%;"></div>
                </div>
            </div>
            <div class="arte-stat-card active" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #10b981;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Aktívne</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; color:#10b981;line-height: .9; margin: 5px 0 -5px 0;"><?php echo $active; ?></div>
                <small style="color:#666;"><?php echo $active_percent; ?>%</small>
                <div class="arte-progress-bar" style="background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden; margin: 10px 0;">
                    <div class="arte-progress-fill" style="width:<?php echo $active_percent; ?>%; background:#10b981; height:100%;"></div>
                </div>
            </div>
            <div class="arte-stat-card warning" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #f60;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Grace Period</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; margin: 5px 0; color:#f60;"><?php echo $in_grace; ?></div>
                <small style="color:#666;">Po expirácii</small>
            </div>
            <div class="arte-stat-card danger" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #ef4444;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Expirované</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; margin: 5px 0; color:#ef4444;"><?php echo $expired; ?></div>
                <small style="color:#666;"><?php echo $expired_percent; ?>%</small>
            </div>
            <div class="arte-stat-card info" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #5a5555;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Pozastavené</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; margin: 5px 0; color:#FF6600;"><?php echo $suspended; ?></div>
            </div>
            <div class="arte-stat-card active" style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; border-left: 4px solid #10b981;">
                <div class="arte-stat-label" style="font-size: 12px; color: #666; text-transform: uppercase;">Neobmedzené</div>
                <div class="arte-stat-number" style="font-size: 28px; font-weight: bold; margin: 5px 0; color:#10b981;">∞ <?php echo $perpetual; ?></div>
                <small style="color:#666;">Bez expirácie</small>
            </div>
        </div>

        <!-- Sekcia pre správy -->
        <div class="expirydomains">
        <?php if ($global_message || !empty($domain_messages)): ?>
        <div class="arte-section">
            <h4 style="margin: 0 0 15px 0; font-size: 18px; color: #374151; border-bottom: 2px solid #f60; padding-bottom: 8px;">📢 Správy k licenciám</h4>
            <ul style="margin:0; padding-left:20px;">
                <?php if ($global_message): ?>
                    <?php
					$global_message->message_priority === 'warning' ? '#f60' : '#3b82f6';
                    $message_bg = $global_message->message_priority === 'danger' ? '#fef2f2' : 
                                  ($global_message->message_priority === 'warning' ? '#FCF8F7' : '#dbeafe');
                    ?>
                    <li style="margin:5px 0; padding:8px; background:<?php echo $message_bg; ?>; border-left:4px solid <?php echo $message_color; ?>; border-radius:4px;">
                        <strong>Globálna správa:</strong> <?php echo wp_kses_post($global_message->message); ?>
                    </li>
                <?php endif; ?>
                <?php foreach ($domain_messages as $msg): ?>
                    <?php
                    $message_color = $msg->message_priority === 'danger' ? '#ef4444' : 
                                    ($msg->message_priority === 'warning' ? '#f60' : '#3b82f6');
                    $message_bg = $msg->message_priority === 'danger' ? '#fef2f2' : 
                                  ($msg->message_priority === 'warning' ? '#FCF8F7' : '#dbeafe');
                    ?>
                    <li style="margin:5px 0; padding:8px; background:<?php echo $message_bg; ?>; border-left:4px solid <?php echo $message_color; ?>; border-radius:4px;">
                        <a href="https://<?php echo $msg->domain; ?>" target="_blank"><?php echo esc_html($msg->domain); ?>:</a><br><span style="font-size:12px"><?php echo wp_kses_post($msg->message); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Expirácie Timeline -->
        <div class="arte-section">
            <h4 style="margin: 0 0 15px 0; font-size: 18px; color: #374151; border-bottom: 2px solid #f60; padding-bottom: 8px;">⏰ Expirácie</h4>
            <table style="width:100%; font-size:13px;">
                <tr>
                    <td style="padding:8px 0;">
                        <strong>Expiruje do 7 dní:</strong>
                        <span class="arte-badge <?php echo $expiring_7 > 0 ? 'danger' : 'success'; ?>" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; 
						<?php
							if ($expiring_7 == 1){$sk_7licences ='licencia';}
							else if ($expiring_7 > 1 && $expiring_7 < 5){$sk_7licences ='licencie';}
							else {$sk_7licences ='licencií';}
						
						echo $expiring_7 > 0 ? 'background: #fee2e2; color: #991b1b;' : 'background: #d1fae5; color: #065f46;'; ?>">
                            <?php echo $expiring_7.' '.$sk_7licences; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0;">
                        <strong>Expiruje do 30 dní:</strong>
                        <span class="arte-badge <?php echo $expiring_30 > 0 ? 'warning' : 'success'; ?>" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; <?php 
							if ($expiring_30 == 1){$sk_30licences ='licencia';}
							else if ($expiring_30 > 1 && $expiring_30 < 5){$sk_30licences ='licencie';}
							else {$sk_30licences ='licencií';}
							echo $expiring_30 > 0 ? 'background: #FCF8F7; color: #92400e;' : 'background: #d1fae5; color: #065f46;'; ?>">
                            <?php echo $expiring_30.' '.$sk_30licences; ?> 
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0;">
                        <strong>Expiruje do 60 dní:</strong>
                        <span class="arte-badge info" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background:#dbeafe; color:#1e40af;">
                            <?php 
								if ($expiring_60 == 1){$sk_60licences ='licencia';}
								else if ($expiring_60 > 1 && $expiring_60 < 5){$sk_60licences ='licencie';}
								else {$sk_60licences ='licencií';}

								echo $expiring_60.' '.$sk_60licences; ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Kritické upozornenia -->
        <?php if ($expiring_7 > 0 || $in_grace > 0): ?>
        <div class="arte-section" style="border-left:4px solid #ef4444; background:#fef2f2; margin: 20px 0; padding: 15px; border-radius: 6px;">
            <h4 style="margin: 0 0 15px 0; font-size: 18px; color: #dc2626; border-bottom: 2px solid #dc2626; padding-bottom: 8px;">🚨 Kritické upozornenia</h4>
            <ul style="margin:0; padding-left:20px;">
                <?php if ($expiring_7 > 0): ?>
                <li style="color:#991b1b; margin:5px 0;">
                    <strong><?php echo $expiring_7.' '.$sk_7licences; ?></strong> expiruje do 7 dní!
                </li>
                <?php endif;
				if ($in_grace == 1){$sk_grace ='licencia';}
				else if ($in_grace > 1 && $in_grace < 5){$sk_grace ='licencie';}
				else {$sk_grace ='licencií';}
				
                if ($in_grace > 0): ?>
                <li style="color:#991b1b; margin:5px 0;">
                    <strong><?php echo $in_grace.' '.$sk_grace; ?></strong> je v grace period (už expirované)
                </li>
                <?php endif; ?>
            </ul>
            <p style="margin:10px 0 0 0;">
                <a href="<?php echo admin_url('admin.php?page=artefactum-licences'); ?>" class="button button-primary button-small" style="background:#f60; border-color:#f60; color:#fff; padding:5px 15px; text-decoration:none;">
                    Zobraziť všetky licencie →
                </a>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Zoznam expirujúcich licencií -->
        <?php if (!empty($expiring_list)): ?>
        <div class="arte-section">
            <h4 style="margin: 0 0 15px 0; font-size: 18px; color: #374151; border-bottom: 2px solid #f60; padding-bottom: 8px;">📋 Expirujú tento mesiac</h4>
            <table class="arte-table" style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px;">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd;">
                        <th style="padding: 8px; text-align: left; font-weight: 600; background-color: #c4b5ae;">Doména</th>
                        <th style="padding: 8px; text-align: left; font-weight: 600; background-color: #c4b5ae;">Klient</th>
                        <th style="padding: 8px; text-align: right; font-weight: 600; background-color: #c4b5ae;">Expirácia</th>
                        <th style="padding: 8px; text-align: right; font-weight: 600; background-color: #c4b5ae;">Zostáva</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiring_list as $item): ?>
                        <?php
                        $days_color = $item->days_left <= 7 ? '#ef4444' : ($item->days_left <= 14 ? '#f60' : '#3b82f6');
                        $days_text = $item->days_left === 1 ? '1 deň' : ($item->days_left <= 4 ? $item->days_left . ' dni' : $item->days_left . ' dní');
                        ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 8px;text-align: left;"><a href="https://<?php echo $item->domain; ?>" target="_blank"><strong><?php echo esc_html($item->domain); ?></strong></a></td>
                            <td style="padding: 8px;text-align: left;"><?php echo esc_html($item->client_name ?: '-'); ?></td>
                            <td style="padding: 8px;text-align: right;"><?php echo date('d.m.Y', strtotime($item->expiry_date)); ?></td>
                            <td style="padding: 8px;text-align: right;">
                                <span style="color:<?php echo $days_color; ?>; font-weight:bold;">
                                    <?php echo $days_text; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        </div>
        
        <!-- Top aktívne domény -->
        <?php if (!empty($top_domains)): ?>
        <div class="arte-section">
            <h4 style="margin: 0 0 15px 0; font-size: 18px; color: #374151; border-bottom: 2px solid #f60; padding-bottom: 8px;">🏆 Top 10 najaktívnejších domén</h4>
            <table class="arte-table" style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px;">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd;">
                        <th style="padding: 8px; text-align: left; font-weight: 600; background-color: #c4b5ae;">Doména</th>
                        <th style="padding: 8px; text-align: center; font-weight: 600; background-color: #c4b5ae;">Počet kontrol</th>
                        <th style="padding: 8px; text-align: center; font-weight: 600; background-color: #c4b5ae;">Posledná kontrola</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_domains as $item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 8px;text-align: left;"><a href="https://<?php echo $item->domain; ?>" target="_blank"><strong><?php echo esc_html($item->domain); ?></strong></a></td>
                            <td style="padding: 8px;text-align:center;">
                                <span class="arte-badge success" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">
                                    <?php echo number_format($item->check_count); ?>×
                                </span>
                            </td>
                            <td style="padding: 8px;text-align:center;">
                                <small><?php echo $item->last_seen ? date('d.m.Y H:i', strtotime($item->last_seen)) : 'Nikdy'; ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        
        <!-- DOLNÁ SEKCIA - INFO PANEL -->
        <div style="display:flex;gap:15px;flex-wrap:wrap;margin-top:30px;flex-direction: row;justify-content: center;">
        <div style="padding:20px;background:#f0f9ff;border-left:4px solid #3b82f6;border-radius:9px;">
            <h4 style="margin:0 0 10px 0;color:#1e40af;">ℹ️ Súhrn systému</h4>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;font-size:13px;">
                <div>
                    <strong>Databázy:</strong><br>
                    <small style="color:#666;">
                        • WP DB: artefactum_sk30<br>
                        • DATA DB: artefactum_dat
                    </small>
                </div>
                <div>
                    <strong>Tabuľky monitorované:</strong><br>
                    <small style="color:#666;">
                        • Licencie<br>
                        • Faktúry<br>
                        • Ročné/mesačné služby<br>
                        • Email účty
                    </small>
                </div>
                <div>
                    <strong>Posledná aktualizácia:</strong><br>
                    <small style="color:#666;">
                        <?php echo date('d.m.Y H:i:s'); ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Dodatočné info -->
        <div style="padding:20px;background:#f0f9ff;border-left:4px solid #3b82f6;border-radius:9px;">
            <p style="margin:0; font-size:12px; color:#1e40af;">
                <strong>ℹ️ Info:</strong><br>
                • Wildcard domény: <strong><?php echo $wildcards; ?></strong><br>
                • API kontroly (7 dní): <strong><?php echo number_format($checks_7days); ?></strong><br>
                • Posledná aktualizácia: <strong><?php echo date('d.m.Y H:i:s'); ?></strong>
            </p>
        </div>
        </div>

        <p class="statbuttons">
            <a href="<?php echo admin_url('admin.php?page=artefactum-licences'); ?>" class="button button-Tprimary">
                🧩 Zobraziť všetky licencie
            </a>
            <a href="<?php echo admin_url('admin.php?page=artefactum-logs'); ?>" class="button button-Tsecondary">
                📋 Zobraziť logy
            </a>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

// --- SHORTCODE: ZOBRAZENIE SPRÁV KLIENTA NA FRONTENDE --- //
add_shortcode('artefactum_client_messages', 'artefactum_client_messages_shortcode');
function artefactum_client_messages_shortcode() {
    if (!is_user_logged_in()) return '<p>Pre zobrazenie správ sa prihláste.</p>';
    $user = wp_get_current_user();
    global $wpdb;

    $licences = $wpdb->get_col($wpdb->prepare(
        "SELECT license_key FROM artefactum_licences WHERE user_email = %s", $user->user_email
    ));

    if (empty($licences)) return '<p>Pre váš účet neboli nájdené žiadne správy.</p>';

    $messages = $wpdb->get_results("
        SELECT message_title, message_content, date_created 
        FROM artefactum_messages 
        WHERE license_key IN ('" . implode("','", array_map('esc_sql', $licences)) . "')
        ORDER BY date_created DESC
    ");

    if (!$messages) return '<p>Nemáte žiadne nové správy.</p>';

    ob_start();
    echo '<div class="artefactum-client-messages">';
    foreach ($messages as $msg) {
        echo '<div class="message">';
        echo '<h4>' . esc_html($msg->message_title) . '</h4>';
        echo '<p>' . wp_kses_post($msg->message_content) . '</p>';
        echo '<small>' . esc_html($msg->date_created) . '</small>';
        echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}

// 📄 Generátor unikátneho licenčného kľúča
function artefactum_generate_unique_license_key($wpdb) {
    do {
        $letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $randPart = substr(str_shuffle($letters), 0, 2) . rand(10, 99);
        $randPart2 = substr(str_shuffle($letters), 0, 2) . rand(10, 99);

        $now = new DateTime();
        $month = str_pad($now->format('n'), 2, '0', STR_PAD_LEFT);
        $year = $now->format('y');

        $key = "ART-$randPart-$randPart2-$month$year";

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->licences} WHERE license_key = %s",
                $key
            )
        );
    } while ($exists > 0);

    return $key;
}

// === KONFIGURÁCIA DATABÁZ ===
define('ARTE_DATA_DB_NAME', 'artefactum_dat');
define('ARTE_DATA_DB_USER', 'artefactum_dat');
define('ARTE_DATA_DB_PASS', 'c2q6v12C');
define('ARTE_DATA_DB_HOST', 'db-05.nameserver.sk');

// === PRIPOJENIE NA DATA DB ===
function arte_get_extended_data_db() {
    static $db_dat = null;
    
    if ($db_dat === null) {
        $db_dat = new wpdb(
            ARTE_DATA_DB_USER,
            ARTE_DATA_DB_PASS,
            ARTE_DATA_DB_NAME,
            ARTE_DATA_DB_HOST
        );
        
        if (!empty($db_dat->error)) {
            error_log('arte-extended: DATA DB CONNECTION FAILED');
            return null;
        }
    }
    
    return $db_dat;
}

/**
 * === NOVÝ SHORTCODE: Rozšírené štatistiky ===
 * [artefactum_extended_statistics]
 */
add_shortcode('artefactum_extended_statistics', 'artefactum_extended_statistics_shortcode');

function artefactum_extended_statistics_shortcode($atts) { 
    global $wpdb;
    $db_dat = arte_get_extended_data_db();
    
    if (!$db_dat) {
        return '<div style="background:#fee2e2;padding:20px;border-radius:5px;color:#991b1b;">
                ❌ Chyba pripojenia k DATA DB
                </div>';
    }
    
    // === 1️ LICENCIE (z WP DB) ===
    $licences_stats = [
        'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences}"),
        'active' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active'"),
        'expiring_30' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")
    ];
    
    // === 2 ROČNÉ SLUŽBY - expirujúce (DATA DB) ===
    $yearly_services = $db_dat->get_results("
        SELECT 
            customeruid,
            companyname,
            domena,
            nazovsluyby,
            cenasluzbyrok,
            datumexpiracie,
            DATEDIFF(datumexpiracie, NOW()) as days_left
        FROM predplatenerocnesluzby
        WHERE datumexpiracie IS NOT NULL
        AND datumexpiracie BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)
        ORDER BY datumexpiracie ASC
        LIMIT 20
    ");
    
    $yearly_critical = $db_dat->get_var("
        SELECT COUNT(*) FROM predplatenerocnesluzby
        WHERE datumexpiracie BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
    ");
    
    // === 3 NEUHRADENÉ FAKTÚRY (DATA DB) ===
    $unpaid_invoices = $db_dat->get_results("
        SELECT 
			customeruid,
			companyname,
            slofaktry,
			popis,
			hradacelkom,
			dtumsplatnosti,
			DATEDIFF(NOW(), dtumsplatnosti) as days_overdue
		FROM invoicesartefactum
		WHERE (dtumhrady IS NULL OR dtumhrady = '')
		ORDER BY dtumsplatnosti ASC
		LIMIT 20;
    ");


    $unpaid_total = $db_dat->get_var("
        SELECT SUM(hradacelkom) FROM invoicesartefactum WHERE dtumhrady IS NULL OR dtumhrady = ''
    ");

    // === 4️ NEUHRADENÉ ZÁLOHOVÉ FAKTÚRY (DATA DB) ===
    $unpaid_advanced = $db_dat->get_results("
        SELECT 
            customeruid,
            companyname,
            cislopredfaktury,
            popis,
            celkomsdph,
            datumsplatnosti,
            DATEDIFF(NOW(), datumsplatnosti) as days_overdue
        FROM advancedinvoices
        WHERE stav = 'Neuhradené'
        ORDER BY days_overdue DESC, datumsplatnosti ASC
        LIMIT 20
    ");

    $unpaidadvanced_total = $db_dat->get_var("
        SELECT SUM(celkomsdph) FROM advancedinvoices WHERE stav = 'Neuhradené'
    ");

    $unpaid_count = count($unpaid_invoices);
    $unpaid_advcount = count($unpaid_advanced);
    
    // === 5️⃣ MESAČNÉ SLUŽBY - aktívne (DATA DB) ===
    $monthly_services = $db_dat->get_var("
        SELECT COUNT(*) FROM predplatenemesacnesluzby
        WHERE predplatenedo BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
    ");
    
    // === 6️⃣ EMAIL ÚČTY (DATA DB) ===
    $email_accounts = $db_dat->get_var("SELECT COUNT(*) FROM emailaccounts");
    $email_total_quota = $db_dat->get_var("SELECT SUM(kvotamb) FROM emailaccounts");

    $email_quota_display = $email_total_quota >= 1024 
        ? round($email_total_quota / 1024, 2) . ' GB' 
        : round($email_total_quota, 2) . ' MB';
    
    // === TOP 10 EMAIL ÚČTOV ===
    $top_email_accounts = $db_dat->get_results("
        SELECT 
            customeruid,
            companyname,
            email,
            kvotamb
        FROM emailaccounts
        ORDER BY kvotamb DESC
        LIMIT 10
    ");
    
    // === VÝSTUP HTML ===
    ob_start();
    ?>
    <div class="arte-extended-stats" style="max-width:1400px;margin:40px auto;padding:20px;">
        
        <h2 style="text-align:center;color:#f60;margin-bottom:30px;">
            📊 Artefactum - statistics
        </h2>
        
        <!-- HLAVNÉ KARTY -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;margin-bottom:30px;">
            
            <!-- Ročné služby - kritické -->
            <div style="background:#fff;padding:15px;border-radius:8px;border-left:4px solid #ef4444;box-shadow:0 2px 5px rgba(0,0,0,0.1);text-align:center;">
                <div style="font-size:12px;color:#666;text-transform:uppercase;">Ročné služby</div>
                <div style="font-size:32px;font-weight:bold;color:#ef4444;margin:5px 0;">
                    <?php echo $yearly_critical; ?>
                </div>
                <small style="color:#666;">expirujú <strong>do 30 dní</strong></small>
            </div>
            
            <!-- Mesačné služby -->
            <div style="background:#fff;padding:15px;border-radius:8px;border-left:4px solid #3b82f6;box-shadow:0 2px 5px rgba(0,0,0,0.1);text-align:center;">
                <div style="font-size:12px;color:#666;text-transform:uppercase;">Mesačné služby</div>
                <div style="font-size:32px;font-weight:bold;color:#3b82f6;margin:5px 0;">
                    <?php echo $monthly_services; ?>
                </div>
                <small style="color:#666;">expirujú <strong>do 30 dní</strong></small>
            </div>
            
            <!-- Neuhradené faktúry -->
            <div style="background:#fff;padding:15px;border-radius:8px;border-left:4px solid #dc2626;box-shadow:0 2px 5px rgba(0,0,0,0.1);text-align:center;">
                <div style="font-size:12px;color:#666;text-transform:uppercase;">Neuhradené faktúry</div>
                <div style="font-size:32px;font-weight:bold;color:#dc2626;margin:5px 0;">
                    <?php echo $unpaid_count; ?>
                </div>
                <?php 
                if ($unpaid_total<1){echo '<small style="color:#666;">';}
                else {echo '<small style="color:#dc2626">- <strong>';}           
                echo number_format($unpaid_total, 2); ?></strong> €</small>
            </div>
            
            <!-- Neuhradené predfaktúry -->
            <div style="background:#fff;padding:15px;border-radius:8px;border-left:4px solid #dc2626;box-shadow:0 2px 5px rgba(0,0,0,0.1);text-align:center;">
                <div style="font-size:12px;color:#666;text-transform:uppercase;">Neuhradené predfaktúry</div>
                <div style="font-size:32px;font-weight:bold;color:#dc2626;margin:5px 0;">
                    <?php echo $unpaid_advcount; ?>
                </div>
                <?php 
                if ($unpaidadvanced_total<1){echo '<small style="color:#666;">';}
                else {echo '<small style="color:#dc2626">- <strong>';}
                echo number_format($unpaidadvanced_total, 2); ?></strong> €</small>
            </div>
            
            <!-- Email účty -->
            <div style="background:#fff;padding:15px;border-radius:8px;border-left:4px solid #8b5cf6;box-shadow:0 2px 5px rgba(0,0,0,0.1);text-align:center;;">
                <div style="font-size:12px;color:#666;text-transform:uppercase;">Email účty</div>
                <div style="font-size:32px;font-weight:bold;color:#8b5cf6;margin:5px 0;">
                    <?php echo $email_accounts; ?>
                </div>
                <small style="color:#666"><?php echo $email_quota_display; ?> celková kvóta</small>
            </div>
            
        </div>
		<div style="display:flex;gap:15px;flex-wrap:wrap;flex-direction: row;justify-content: center;">

        <?php
			// fakturovane tohto roku
		$current_year = date('Y');
		$db_dat = arte_get_extended_data_db();
        
        // === VŠETKY ROČNÉ SLUŽBY PRE MESAČNÝ ROZPAD ===
        $all_yearly_services = $db_dat->get_results("
        SELECT 
            nazovsluyby,
            cenasluzbyrok,
            datumexpiracie,
            MONTH(datumexpiracie) as expiry_month
        FROM predplatenerocnesluzby
        WHERE YEAR(datumexpiracie) = YEAR(NOW())
        AND nazovsluyby NOT LIKE '%NEPREDLŽOVAŤ%' AND nazovsluyby NOT LIKE '%artefactum%' AND nazovsluyby NOT LIKE '%expressar%' AND nazovsluyby NOT LIKE '%artepaint%'
        ");


        // === VÝPOČET MESAČNÉHO ROZPADU ===
        $monthly_breakdown = array_fill(1, 12, [
            'sk' => ['count' => 0, 'sum' => 0],
            'eu' => ['count' => 0, 'sum' => 0],
            'com' => ['count' => 0, 'sum' => 0],
            'ssl' => ['count' => 0, 'sum' => 0],
            'hosting' => ['count' => 0, 'sum' => 0]
        ]);

        if (!empty($all_yearly_services)) {
            foreach ($all_yearly_services as $service) {
                $month = (int)$service->expiry_month;
                $price = (float)$service->cenasluzbyrok;
                $name = strtolower($service->nazovsluyby);
                
                // Evidencie domén
                if (strpos($name, 'evidencia') !== false) {
                    if (preg_match('/\.sk\b/i', $name)) {
                        $monthly_breakdown[$month]['sk']['count']++;
                        $monthly_breakdown[$month]['sk']['sum'] += ($price - 16.50);
                    } elseif (preg_match('/\.eu\b/i', $name)) {
                        $monthly_breakdown[$month]['eu']['count']++;
                        $monthly_breakdown[$month]['eu']['sum'] += ($price - 12);
                    } elseif (preg_match('/\.com\b/i', $name)) {
                        $monthly_breakdown[$month]['com']['count']++;
                        $monthly_breakdown[$month]['com']['sum'] += ($price - 18);
                    }
                }
                
                // SSL certifikáty
                if (strpos($name, 'basic ssl') !== false) {
                    $monthly_breakdown[$month]['ssl']['count']++;
                    $monthly_breakdown[$month]['ssl']['sum'] += $price;
                }
                
                // Hostingy
                if (strpos($name, 'hosting') !== false) {
                    $monthly_breakdown[$month]['hosting']['count']++;
                    $monthly_breakdown[$month]['hosting']['sum'] += $price;
                }
            }
        }

	// === POČTY ZÁZNAMOV ZA ROK (PRE KAŽDÝ TYP) ===
		$yearly_counts = [
		    'sk' => 0,
		    'eu' => 0,
		    'com' => 0,
		    'ssl' => 0,
		    'hosting' => 0
		];

		if (!empty($all_yearly_services)) {
		    foreach ($all_yearly_services as $service) {
			$name = strtolower($service->nazovsluyby);
			
			// Evidencie domén
			if (strpos($name, 'evidencia') !== false) {
			    if (preg_match('/\.sk\b/i', $name)) {
				$yearly_counts['sk']++;
			    } elseif (preg_match('/\.eu\b/i', $name)) {
				$yearly_counts['eu']++;
			    } elseif (preg_match('/\.com\b/i', $name)) {
				$yearly_counts['com']++;
			    }
			}
			
			// SSL certifikáty
			if (strpos($name, 'basic ssl') !== false) {
			    $yearly_counts['ssl']++;
			}
			
			// Hostingy
			if (strpos($name, 'hosting') !== false) {
			    $yearly_counts['hosting']++;
			}
		    }
		}



        // === MESAČNÝ ROZPAD PRÍJMOV Z ROČNÝCH SLUŽBI ===      
		if ($db_dat) {
			// ✅ Priamy SQL dotaz - SPOĽAHLIVÝ
			$total_invoiced = $db_dat->get_var($db_dat->prepare("
				SELECT SUM(hradacelkom) 
				FROM invoicesartefactum
				WHERE dtumhrady BETWEEN %s AND %s
			", 
				$current_year . '-01-01',
				$current_year . '-12-31'
			));
			
			// Formatovanie výstupu ako v tvojom fungujúcom príklade
			echo '<div style="padding:15px 20px;background:#fff;border-left:4px solid #10b981;margin:20px 0;border-radius:5px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
			echo '<span style="font-size:16px;color:#666;"> <strong style="color:#10b981;">' . $current_year . '</strong> - uhradené faktúry celkom: </span>';
			echo '<strong style="font-size:18px;color:#10b981;margin-left:10px;display:inline-block">' . number_format($total_invoiced, 2, ',', ' ') . ' €</strong>';
			echo '</div>';
		} else {
			echo '<div style="background:#fee2e2;padding:15px;border-radius:5px;color:#991b1b;">⚠️ Chyba pripojenia k databáze</div>';
		}
		?>
        </div>
        <!-- MESAČNÝ ROZPAD PRÍJMOV -->
    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin:20px 0;">
        <h4 style="margin:0 0 15px 0;color:#f60;border-bottom:2px solid #f60;padding-bottom:8px;">
            📅 Predpokladané príjmy z ročných služieb
        </h4>
        
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:12px;border-collapse:collapse;min-width:900px;margin-bottom:10px !important; ">
                <thead>
                    <tr style="background:#c4b5ae;">
                        <th style="background:#c4b5ae;padding:8px;text-align:left;border:1px solid #ddd;">Typ</th>
                        <?php
                        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
                        foreach ($months as $m) {
                            echo "<th style='background:#c4b5ae;padding:8px;text-align:center;border:1px solid #ddd;'>$m</th>";
                        }
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $types = [
			    'sk' => ['label' => 'SK domény', 'color' => '#10b981'],
			    'eu' => ['label' => 'EU domény', 'color' => '#3b82f6'],
			    'com' => ['label' => 'COM domény', 'color' => '#8b5cf6'],
			    'ssl' => ['label' => 'SSL certifikáty', 'color' => '#f59e0b'],
			    'hosting' => ['label' => 'Hostingy', 'color' => '#ef4444']
			];

			foreach ($types as $key => $type) {
			    echo "<tr>";
			    echo "<td style='padding:8px;border:1px solid #ddd;font-weight:bold;color:{$type['color']};'>";
			    echo "{$type['label']} <span style='color:#666;font-weight:normal;'>({$yearly_counts[$key]})</span>";
			    echo "</td>";
                        
                        for ($m = 1; $m <= 12; $m++) {
                            $count = $monthly_breakdown[$m][$key]['count'];
                            $sum = $monthly_breakdown[$m][$key]['sum'];
                            
                            if ($count > 0) {
                                echo "<td style='color:#666;padding:8px 4px;border:1px solid #ddd;text-align:right;background:#f9fafb;'>";
                                echo "({$count}) <span style='color:{$type['color']};'>" . number_format($sum, 2) . " €</span>";
                                echo "</td>";
                            } else {
                                echo "<td style='padding:8px;border:1px solid #ddd;text-align:center;color:#ccc;'>-</td>";
                            }
                        }
                        echo "</tr>";
                    }
                    ?>
                    
                    <!-- HORIZONTÁLNA ČIARA -->
                    <tr style="background:#e5e7eb;">
                        <td colspan="13" style="padding:1px;"></td>
                    </tr>
                    <!-- CELKOM ZA MESIAC -->
                    <tr style="background:transparent;">
                        <td style="padding:10px;border:1px solid #ddd;color:#374151;">CELKOM</td>
                        <?php
                        $grand_total_sum = 0; // Globálny súčet
                        
                        for ($m = 1; $m <= 12; $m++) {
                            $total_count = 0;
                            $total_sum = 0;
                            
                            foreach (['sk', 'eu', 'com', 'ssl', 'hosting'] as $key) {
                                $total_count += $monthly_breakdown[$m][$key]['count'];
                                $total_sum += $monthly_breakdown[$m][$key]['sum'];
                            }
                            
                            $grand_total_sum += $total_sum; // Pripočítaj k celkovému súčtu
                            
                            if ($total_count > 0) {
                                echo "<td style='color:#666;padding:10px 1px;border:1px solid #ddd;text-align:right;'>";
                                echo "({$total_count}) " ."<strong style='color:#10b981;'>". number_format($total_sum, 2) . "</strong> €";
                                echo "</td>";
                            } else {
                                echo "<td style='padding:10px;border:1px solid #ddd;text-align:center;color:#999;'>-</td>";
                            }
                        }
                        $grand_average_sum = $grand_total_sum / 12;
                        ?>
                    </tr>
                </tbody>
            </table>
            <!-- ROČNÝ CELKOVÝ SÚČET -->
            <div style="padding:12px 5px;background:rgb(196 181 174 / 20%);border-left:4px solid #ff6600;border-radius:4px;text-align:center;margin:0;">  Predpokladaný ročný príjem celkom: <strong style="color:#10b981;font-size:18px;margin-left:10px;"><?php echo number_format($grand_total_sum, 2); ?> €</strong> <span style="padding-left:20px;color:#666;font-size:14px;">⍉ <?php echo number_format($grand_average_sum, 2); ?> € /mes.</span>
             </div>
        </div>
    </div>
        <!-- ZOZNAM TABULIEK -->
        <div style="max-width:100%;margin-top:30px;">
    
    <!-- 🚨 NEUHRADENÉ FAKTÚRY -->
    <?php if (!empty($unpaid_invoices)): ?>
    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin-bottom:20px;">
        <h3 style="margin:0 0 15px 0;color:red;border-bottom:2px solid #dc2626;padding-bottom:8px;">
            🚨 Neuhradené faktúry (TOP 20)
        </h3>
        <table class="arte-responsive-table" style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:10px !important;">
            <thead>
                <tr style="background-color: #c4b5ae;">
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Klient</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:center;">Faktúra #</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Fakturované</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Dátum splatnosti</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Suma</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Dni po splatnosti</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unpaid_invoices as $invoice): ?>
                    <?php
                    $overdue_color = $invoice->days_overdue < 0 ? '#6b7280' : ($invoice->days_overdue > 14 ? '#f60' : 'red');
                    ?>
                    <tr class="collapsed">
                        <td data-label="Klient" style="padding:8px;">
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;">
                                <?php echo esc_html($invoice->companyname); ?>
                            </code>
                        </td>
                        <td data-label="Faktúra #" style="padding:8px;text-align:center;">
                            <strong><?php echo esc_html($invoice->slofaktry); ?></strong>
                        </td>
                        <td data-label="Fakturované" style="padding:8px;text-align:left;">
                            <?php echo esc_html($invoice->popis); ?>
                        </td>
                        <td data-label="Splatnosť" style="padding:8px;text-align:right;">
                            <?php echo date('d.m.Y', strtotime($invoice->dtumsplatnosti)); ?>
                        </td>
                        <td data-label="Suma" style="padding:8px;text-align:right;">
                            <strong><?php echo number_format($invoice->hradacelkom, 2); ?> €</strong>
                        </td>
                        <td data-label="Po splatnosti" style="padding:8px;text-align:right;">
                            <span style="color:<?php echo $overdue_color; ?>;font-weight:bold;">
                                <?php echo $invoice->days_overdue; ?> dní
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top:15px;padding:12px 5px;background:rgba(196 181 174 / 15%);border-left:4px solid red;border-radius:4px;">
            <span style="color:red;">Celková suma dlhu: <strong style="display:inline-block">- <?php echo number_format($unpaid_total, 2); ?> €</strong></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 🚨 NEUHRADENÉ PREDFAKTÚRY -->
    <?php if (!empty($unpaid_advanced)): ?>
    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin-bottom:20px;">
        <h4 style="margin:0 0 15px 0;color:#dc2626;border-bottom:2px solid #dc2626;padding-bottom:8px;">
            ‼ Neuhradené predfaktúry (TOP 20)
        </h3>
        <table class="arte-responsive-table" style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:10px !important;">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb;">
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Klient</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:center;">Predfaktúra #</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Faktúrované</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Dátum splatnosti</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Suma</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Po splatnosti</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unpaid_advanced as $advinvoice): ?>
                    <?php
                    $overdue_advcolor = $advinvoice->days_overdue > 30 ? '#dc2626' : 
                                       ($advinvoice->days_overdue > 14 ? '#f60' : '#6b7280');
                    ?>
                    <tr class="collapsed">
                        <td data-label="Klient" style="padding:8px;">
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;">
                                <?php echo esc_html($advinvoice->companyname); ?>
                            </code>
                        </td>
                        <td data-label="Predfaktúra #" style="padding:8px;text-align:center;">
                            <strong><?php echo esc_html($advinvoice->cislopredfaktury); ?></strong>
                        </td>
                        <td data-label="Fakturované" style="padding:8px;text-align:left;">
                            <?php echo esc_html($advinvoice->popis); ?>
                        </td>
                        <td data-label="Splatnosť" style="padding:8px;text-align:right;">
                            <?php echo date('d.m.Y', strtotime($advinvoice->datumsplatnosti)); ?>
                        </td>
                        <td data-label="Suma" style="padding:8px;text-align:right;">
                            <strong><?php echo number_format($advinvoice->celkomsdph, 2); ?> €</strong>
                        </td>
                        <td data-label="Po splatnosti" style="padding:8px;text-align:right;">
                            <span style="color:<?php echo $overdue_advcolor; ?>;font-weight:bold;">
                                <?php echo $advinvoice->days_overdue; ?> dní
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top:15px;padding:12px 5px;background:#fee2e2;border-left:4px solid #dc2626;border-radius:4px;">
			<span style="color:#991b1b;">Neuhradené predfaktúry celkom: <strong style="display:inline-block">- <?php echo number_format($unpaidadvanced_total, 2); ?> €</strong></span>
        </div>
    </div>	
    <?php endif; 

	// ============================================================
	// v funkcii artefactum_extended_statistics_shortcode()
	// ============================================================

	// === POČTY SLUŽIEB PODĽA TYPU (z DATA DB) ===
	$count_evidencia = $db_dat->get_var("
		SELECT COUNT(*) FROM predplatenerocnesluzby 
		WHERE nazovsluyby LIKE '%evidencia%'
	");

	$count_hosting = $db_dat->get_var("
		SELECT COUNT(*) FROM predplatenerocnesluzby 
		WHERE nazovsluyby LIKE '%hosting%'
	");

	$count_alias = $db_dat->get_var("
		SELECT COUNT(*) FROM predplatenerocnesluzby 
		WHERE nazovsluyby LIKE '%alias%'
	");

	// Celkový počet ročných služieb
	$count_yearly_total = $db_dat->get_var("
		SELECT COUNT(*) FROM predplatenerocnesluzby
	");
	?>

	<!-- PREHĽAD SLUŽIEB PODĽA TYPU -->
		<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin-bottom:20px;">
			<h3 style="margin:0 0 15px 0;color:#f60;border-bottom:2px solid #f60;padding-bottom:8px;">
				🔌Prehľad ročných služieb podľa typu 
			</h3><span style="font-size:16px;color:#666;">
			<? echo do_shortcode('[wpdatatable_sum table_id=27 col_id=331 var2="'.date("Y").'" label="Predplatené ročné služby - celkom:"]');
			?></span>
			
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;">
				
				<!-- Evidencie domén -->
				<div style="background:#f0fdf4;padding:15px;border-radius:6px;text-align:center;border-left:4px solid #10b981;">
					<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px;">Evidencie domén</div>
					<div style="font-size:28px;font-weight:bold;color:#10b981;">
						<?php echo intval($count_evidencia); ?>
					</div>
					<small style="color:#666;">registrovaných domén</small>
				</div>
				
				<!-- Hostingy -->
				<div style="background:#eff6ff;padding:15px;border-radius:6px;text-align:center;border-left:4px solid #3b82f6;">
					<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px;">Hostingy</div>
					<div style="font-size:28px;font-weight:bold;color:#3b82f6;">
						<?php echo intval($count_hosting); ?>
					</div>
					<small style="color:#666;">aktívnych hostingov</small>
				</div>
				
				<!-- Alias domény -->
				<div style="background:#fdf4ff;padding:15px;border-radius:6px;text-align:center;border-left:4px solid #a855f7;">
					<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px;">Alias domény</div>
					<div style="font-size:28px;font-weight:bold;color:#a855f7;">
						<?php echo intval($count_alias); ?>
					</div>
					<small style="color:#666;">presmerovaní</small>
				</div>
				
				<!-- Celkom ročných služieb -->
				<div style="background:#f8f9fa;padding:15px;border-radius:6px;text-align:center;border-left:4px solid #6b7280;">
					<div style="font-size:11px;color:#666;text-transform:uppercase;margin-bottom:5px;">Celkom služieb</div>
					<div style="font-size:28px;font-weight:bold;color:#374151;">
						<?php echo intval($count_yearly_total); ?>
					</div>
					<small style="color:#666;">ročných služieb</small>
				</div>
				
			</div>
		</div>
    
    <!-- ⏰ EXPIRUJÚCE ROČNÉ SLUŽBY -->
    <?php if (!empty($yearly_services)): ?>
    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin-bottom:20px;">
        <h3 style="margin:0 0 15px 0;color:#f60;border-bottom:2px solid #f60;padding-bottom:8px;">
            ⏰ Expirujúce ročné služby (do 60 dní)
        </h3>
        <table class="arte-responsive-table" style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:10px !important;">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb;">
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Doména</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Služba</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Cena služby/rok</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Expirácia</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Zostáva</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($yearly_services as $service): ?>
                    <?php
                    $days_color = $service->days_left <= 7 ? '#ef4444' : 
                                ($service->days_left <= 30 ? '#f60' : '#3b82f6');
                    ?>
                    <tr class="collapsed">
                        <td data-label="Doména" style="padding:8px;">
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;">
                                <?php echo esc_html($service->domena); ?>
                            </code>
                        </td>
                        <td data-label="Služba" style="padding:8px;">
                            <strong><?php echo esc_html($service->nazovsluyby); ?></strong>
                        </td>
                        <td data-label="Cena/rok" style="padding:8px;text-align:right;">
                            <?php echo esc_html($service->cenasluzbyrok); ?> €
                        </td>
                        <td data-label="Expirácia" style="padding:8px;text-align:right;">
                            <?php echo date('d.m.Y', strtotime($service->datumexpiracie)); ?>
                        </td>
                        <td data-label="Zostáva" style="padding:8px;text-align:right;">
                            <span style="color:<?php echo $days_color; ?>;font-weight:bold;">
                                <?php echo $service->days_left; ?> dní
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- 📧 TOP 10 emailových účtov -->
    <?php
	/*
		if (!empty($top_email_accounts)): ?>
    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,0.1);margin-bottom:20px;">
        <h3 style="margin:0 0 15px 0;color:#8b5cf6;border-bottom:2px solid #8b5cf6;padding-bottom:8px;">
            📧 TOP 10 emailov (podľa kvóty)
        </h3>
        <table class="arte-responsive-table" style="width:100%;font-size:12px;border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid #e5e7eb;">
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Klient</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:left;">Email</th>
                    <th style="background-color: #c4b5ae;padding:8px;text-align:right;">Kvóta</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_email_accounts as $email): ?>
                    <?php
                    $quota_display = $email->kvotamb >= 1024 
                        ? round($email->kvotamb / 1024, 2) . ' GB' 
                        : round($email->kvotamb, 2) . ' MB';
                    ?>
                    <tr class="collapsed">
                        <td data-label="Klient" style="padding:8px;">
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;">
                                <?php echo esc_html($email->companyname); ?>
                            </code>
                        </td>
                        <td data-label="Email" style="padding:8px;">
                            <strong><?php echo esc_html($email->email); ?></strong>
                        </td>
                        <td data-label="Kvóta" style="padding:8px;text-align:right;;">
                            <strong style="color:#8b5cf6;"><?php echo $quota_display; ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
	*/
	?>
    
        </div>
        
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        console.log('🔧 Artefactum Extended Stats - Responsive script loaded');
        
        function initMobileExpand() {
            var windowWidth = $(window).width();
            console.log('📐 Window width: ' + windowWidth + 'px');
            
            if (windowWidth <= 768) {
                console.log('📱 Mobile mode activated');
                
                // Nastav všetky riadky ako collapsed
                $('.arte-responsive-table tbody tr').addClass('collapsed').removeClass('expanded');
                
                // Odstráň staré listenery
                $('.arte-responsive-table tbody tr').off('click.expand');
                
                // Pridaj nový listener
                $('.arte-responsive-table tbody tr').on('click.expand', function(e) {
                    // Ignoruj kliknutie na linky/code
                    if ($(e.target).is('a, code') || $(e.target).closest('a, code').length) {
                        console.log('🔗 Link clicked, ignoring expand');
                        return;
                    }
                    
                    console.log('👆 Row clicked, toggling');
                    $(this).toggleClass('collapsed expanded');
                });
                
                var rowCount = $('.arte-responsive-table tbody tr').length;
                console.log('✅ Initialized ' + rowCount + ' rows');
                
            } else {
                console.log('🖥️ Desktop mode - expand disabled');
                $('.arte-responsive-table tbody tr').removeClass('collapsed expanded');
                $('.arte-responsive-table tbody tr').off('click.expand');
            }
        }
        
        // Inicializuj pri načítaní
        initMobileExpand();
        
        // Reinicializuj pri zmene veľkosti
        var resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                console.log('🔄 Window resized, reinitializing');
                initMobileExpand();
            }, 250);
        });
    });
    </script>
    
    <?php
    return ob_get_clean();
}

/**
 * === REST API ENDPOINT: Extended Statistics ===
 */
function artefactum_api_extended_stats($request) {
    global $wpdb;
    $db_dat = arte_get_extended_data_db();
    

    // === VŠETKY ROČNÉ SLUŽBY PRE MESAČNÝ ROZPAD ===// === VÝPOČET MESAČNÉHO ROZPADU ===
    $monthly_breakdown = array_fill(1, 12, [
        'sk' => ['count' => 0, 'sum' => 0],
        'eu' => ['count' => 0, 'sum' => 0],
        'com' => ['count' => 0, 'sum' => 0],
        'ssl' => ['count' => 0, 'sum' => 0],
        'hosting' => ['count' => 0, 'sum' => 0]
    ]);

    if (!empty($all_yearly_services)) {
        foreach ($all_yearly_services as $service) {
            $month = (int)$service->expiry_month;
            $price = (float)$service->cenasluzbyrok;
            $name = strtolower($service->nazovsluyby);
            
            // Evidencie domén
            if (strpos($name, 'evidencia') !== false) {
                if (preg_match('/\.sk\b/i', $name)) {
                    $monthly_breakdown[$month]['sk']['count']++;
                    $monthly_breakdown[$month]['sk']['sum'] += ($price - 16.50);
                } elseif (preg_match('/\.eu\b/i', $name)) {
                    $monthly_breakdown[$month]['eu']['count']++;
                    $monthly_breakdown[$month]['eu']['sum'] += ($price - 12);
                } elseif (preg_match('/\.com\b/i', $name)) {
                    $monthly_breakdown[$month]['com']['count']++;
                    $monthly_breakdown[$month]['com']['sum'] += ($price - 18);
                }
            }
            
            // SSL certifikáty
            if (strpos($name, 'basic ssl') !== false) {
                $monthly_breakdown[$month]['ssl']['count']++;
                $monthly_breakdown[$month]['ssl']['sum'] += $price;
            }
            
            // Hostingy
            if (strpos($name, 'hosting') !== false) {
                $monthly_breakdown[$month]['hosting']['count']++;
                $monthly_breakdown[$month]['hosting']['sum'] += $price;
            }
        }
    }

    if (!$db_dat) {
        return new WP_Error('db_error', 'DATA DB connection failed', ['status' => 500]);
    }
    
    $stats = [
        'licences' => [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences}"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active'"),
            'expiring_30' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->licences} WHERE status='active' AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")
        ],
        'yearly_services' => [
            'expiring_30' => (int) $db_dat->get_var("SELECT COUNT(*) FROM predplatenerocnesluzby WHERE datumexpiracie BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)"),
            'expiring_60' => (int) $db_dat->get_var("SELECT COUNT(*) FROM predplatenerocnesluzby WHERE datumexpiracie BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)")
        ],
        'invoices' => [
            'unpaid_count' => (int) $db_dat->get_var("SELECT COUNT(*) FROM invoicesartefactum WHERE stav = 'Neuhradené'"),
            'unpaid_total' => (float) $db_dat->get_var("SELECT SUM(suma) FROM invoicesartefactum WHERE stav = 'Neuhradené'") ?: 0
        ],
        'monthly_services' => [
            'active_this_month' => (int) $db_dat->get_var("SELECT COUNT(*) FROM predplatenemesacnesluzby WHERE mesiac = MONTH(NOW())")
        ],
        'email_accounts' => [
            'total' => (int) $db_dat->get_var("SELECT COUNT(*) FROM emailaccounts")
        ],
        'timestamp' => current_time('mysql')
    ];
    
    return rest_ensure_response($stats);
}

/**
 * Plugin activation - vytvorenie tabuliek
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;
    
    $charset = $wpdb->get_charset_collate();
    
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->licences} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(255) NOT NULL UNIQUE,
        license_key VARCHAR(50) NOT NULL,
        client_name VARCHAR(255) DEFAULT NULL,
        contact_email TEXT DEFAULT NULL,
        expiry_date DATE DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        message TEXT DEFAULT NULL,
        message_priority VARCHAR(20) DEFAULT 'info',
        status VARCHAR(20) DEFAULT 'active',
        last_seen DATETIME DEFAULT NULL,
        check_count INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->licence_logs} (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(255) NOT NULL,
        action VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_domain (domain),
        INDEX idx_created (created_at)
    ) $charset;";

    $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->api_logs} (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        endpoint VARCHAR(100) NOT NULL,
        identifier VARCHAR(255) NOT NULL,
        result VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_endpoint (endpoint),
        INDEX idx_identifier (identifier),
        INDEX idx_created (created_at)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
});

// AJAX handler pre dismiss widgetu (CLIENT SIDE)
add_action('wp_ajax_arte_dismiss_widget', 'arte_dismiss_widget_handler');
function arte_dismiss_widget_handler() {
    check_ajax_referer('arte_widget_dismiss', 'nonce');
    
    $user_id = get_current_user_id();
    if (!$user_id) wp_send_json_error();
    
    $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
    $meta_key = 'arte_dismissed_widget_' . md5($domain);
    
    update_user_meta($user_id, $meta_key, time());
    wp_send_json_success();
}

// AJAX handler pre "zobraziť znovu" (CLIENT SIDE)
add_action('wp_ajax_arte_undismiss_widget', 'arte_undismiss_widget_handler');
function arte_undismiss_widget_handler() {
    check_ajax_referer('arte_widget_dismiss', 'nonce');
    
    $user_id = get_current_user_id();
    if (!$user_id) wp_send_json_error();
    
    $domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
    $meta_key = 'arte_dismissed_widget_' . md5($domain);
    
    delete_user_meta($user_id, $meta_key);
    wp_send_json_success();
}