<?php
/**
 * Plugin Name: Artefactum Rating System
 * Description: Systém hodnotenia služieb s API integráciou - wpDataTables compatible
 * Version: 1.7
 * Author: Artefactum
 */

if (!defined('ABSPATH')) exit;

class ArtefactumRating {
    
    private $db_host = 'db-05.nameserver.sk';
    private $db_name = 'artefactum_dat';
    private $db_user = 'artefactum_dat';
    private $db_pass = 'c2q6v12C';
    private $conn;
    private $customer_names_cache = null;
    
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        add_filter('gform_pre_render_18', [$this, 'populate_form_fields']);
        add_action('gform_after_submission_18', [$this, 'process_rating'], 10, 2);
        add_filter('gform_confirmation_18', [$this, 'custom_confirmation'], 10, 4);
        
        add_shortcode('rating_stats', [$this, 'display_stats']);
        add_action('wp_ajax_generate_rating_link', [$this, 'ajax_generate_link']);
        add_action('wp_ajax_get_rating_debug', [$this, 'ajax_get_debug_info']);
        add_action('wp_ajax_nopriv_get_rating_debug', [$this, 'ajax_get_debug_info']);
        add_filter('gform_replace_merge_tags', [$this, 'add_rating_link_merge_tag'], 10, 3);
        
        // AJAX pre JavaScript
        add_action('wp_ajax_get_all_customer_names', [$this, 'get_customer_names_ajax']);
        add_action('wp_ajax_nopriv_get_all_customer_names', [$this, 'get_customer_names_ajax']);
    }
    
    public function activate_plugin() {
        $db = $this->get_connection();
        if (!$db) {
            wp_die('Nepodarilo sa pripojiť k databáze artefactum_dat.');
        }
        
        $sql1 = "CREATE TABLE IF NOT EXISTS `ratings` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `customeruid` VARCHAR(20) NOT NULL,
          `servicetype` VARCHAR(50) NOT NULL,
          `orderid` VARCHAR(50) DEFAULT NULL,
          `overallrating` TINYINT(1) NOT NULL,
          `technicalrating` TINYINT(1) DEFAULT NULL,
          `designrating` TINYINT(1) DEFAULT NULL,
          `communicationrating` TINYINT(1) DEFAULT NULL,
          `speedrating` TINYINT(1) DEFAULT NULL,
          `improvementtext` TEXT DEFAULT NULL,
          `positivefeedback` TEXT DEFAULT NULL,
          `wouldrecommend` ENUM('yes','no','maybe') DEFAULT NULL,
          `submittedat` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `submittedip` VARCHAR(45) DEFAULT NULL,
          `ispublic` TINYINT(1) DEFAULT 0,
          `responsetimeseconds` INT DEFAULT NULL,
          INDEX `idx_customer` (`customeruid`),
          INDEX `idx_service` (`servicetype`),
          INDEX `idx_date` (`submittedat`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $sql2 = "CREATE TABLE IF NOT EXISTS `ratingtemplates` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `servicetype` VARCHAR(50) NOT NULL UNIQUE,
          `displayname` VARCHAR(100) NOT NULL,
          `questionsjson` JSON NOT NULL,
          `isactive` TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $sql3 = "CREATE TABLE IF NOT EXISTS `ratingtokens` (
          `token` VARCHAR(64) PRIMARY KEY,
          `customeruid` VARCHAR(20) NOT NULL,
          `servicetype` VARCHAR(50) NOT NULL,
          `orderid` VARCHAR(50) DEFAULT NULL,
          `expiresat` DATETIME NOT NULL,
          `usedat` DATETIME DEFAULT NULL,
          INDEX `idx_expiry` (`expiresat`),
          INDEX `idx_customer` (`customeruid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $db->query($sql1);
        $db->query($sql2);
        $db->query($sql3);
        
        $this->insert_default_templates();
        update_option('artefactum_rating_db_version', '1.7');
    }
    
    private function insert_default_templates() {
        $db = $this->get_connection();
        if (!$db) return;
        
        $templates = [
            ['servicetype' => 'webdevelopment', 'displayname' => 'Tvorba webu', 'questions' => json_encode(['designrating' => 'Dizajn', 'technicalrating' => 'Technické', 'communicationrating' => 'Komunikácia'])],
            ['servicetype' => 'seo', 'displayname' => 'SEO', 'questions' => json_encode(['technicalrating' => 'SEO výsledky', 'communicationrating' => 'Reporting'])],
            ['servicetype' => 'support', 'displayname' => 'Podpora', 'questions' => json_encode(['speedrating' => 'Rýchlosť', 'communicationrating' => 'Komunikácia'])],
            ['servicetype' => 'hosting', 'displayname' => 'Hosting', 'questions' => json_encode(['speedrating' => 'Rýchlosť webu', 'technicalrating' => 'Stabilita'])]
        ];
        
        foreach ($templates as $tpl) {
            $stmt = $db->prepare("INSERT IGNORE INTO ratingtemplates (servicetype, displayname, questionsjson) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $tpl['servicetype'], $tpl['displayname'], $tpl['questions']);
            $stmt->execute();
        }
    }
    
    private function get_connection() {
        if (!$this->conn) {
            $this->conn = new mysqli($this->db_host, $this->db_user, $this->db_pass, $this->db_name);
            if ($this->conn->connect_error) {
                error_log('Rating DB failed: ' . $this->conn->connect_error);
                return null;
            }
            $this->conn->set_charset('utf8mb4');
        }
        return $this->conn;
    }
    
    public function init() {
        add_rewrite_endpoint('rating', EP_ROOT);
    }
    
    public function generate_rating_link($customeruid, $servicetype, $orderid = null) {
        $db = $this->get_connection();
        if (!$db) return false;
        
        $token = bin2hex(random_bytes(32));
        $expiresat = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $db->prepare("INSERT INTO ratingtokens (token, customeruid, servicetype, orderid, expiresat) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssss', $token, $customeruid, $servicetype, $orderid, $expiresat);
        
        if ($stmt->execute()) {
            $base_url = (strpos($_SERVER['HTTP_HOST'], 'my.artefactum.sk') !== false) 
                ? 'https://my.artefactum.sk/rating' 
                : 'https://artefactum.sk/rating';
            return $base_url . '?t=' . $token;
        }
        return false;
    }
    
    public function populate_form_fields($form) {
        if (!isset($_GET['t'])) return $form;
        
        $token = sanitize_text_field($_GET['t']);
        $db = $this->get_connection();
        if (!$db) return $form;
        
        $stmt = $db->prepare("SELECT customeruid, servicetype, orderid FROM ratingtokens WHERE token = ? AND expiresat > NOW() AND usedat IS NULL");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($data = $result->fetch_assoc()) {
            foreach ($form['fields'] as &$field) {
                if (isset($field->inputName)) {
                    if ($field->inputName === 'customeruid') $field->defaultValue = $data['customeruid'];
                    if ($field->inputName === 'servicetype') $field->defaultValue = $data['servicetype'];
                    if ($field->inputName === 'orderid') $field->defaultValue = $data['orderid'];
                    if ($field->inputName === 'token') $field->defaultValue = $token;
                }
            }
        }
        return $form;
    }
    
    public function process_rating($entry, $form) {
        $debug_data = [];
        foreach ($entry as $key => $value) {
            $debug_data["Field_{$key}"] = $value;
        }
        set_transient('rating_debug_' . $entry['id'], $debug_data, 600);
        
        $db = $this->get_connection();
        if (!$db) return;
        
        $token = rgar($entry, '1');
        $customeruid = rgar($entry, '3');
        $servicetype = rgar($entry, '4');
        $orderid = rgar($entry, '5');
        
        $overallrating = $this->extract_rating_value(rgar($entry, '6'));
        $improvementtext = rgar($entry, '7');
        $designrating = $this->extract_rating_value(rgar($entry, '8'));
        $technicalrating = $this->extract_rating_value(rgar($entry, '9'));
        $communicationrating = $this->extract_rating_value(rgar($entry, '10'));
        $speedrating = $this->extract_rating_value(rgar($entry, '11'));
        
        $positivefeedback = rgar($entry, '12');
        $wouldrecommend = rgar($entry, '13');
        $ispublic = rgar($entry, '14') ? 1 : 0;
        $submittedip = $_SERVER['REMOTE_ADDR'];
        
        error_log('=== RATING SUBMISSION ===');
        error_log('Customer: ' . $customeruid . ' | Rating: ' . $overallrating);
        
        $stmt = $db->prepare("UPDATE ratingtokens SET usedat = NOW() WHERE token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        
        $stmt = $db->prepare("
            INSERT INTO ratings (
                customeruid, servicetype, orderid, overallrating, 
                technicalrating, designrating, communicationrating, speedrating,
                improvementtext, positivefeedback, wouldrecommend, ispublic, submittedip
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            'sssiiiissssis',
            $customeruid, $servicetype, $orderid, $overallrating,
            $technicalrating, $designrating, $communicationrating, $speedrating,
            $improvementtext, $positivefeedback, $wouldrecommend, $ispublic, $submittedip
        );
        
        if ($stmt->execute()) {
            error_log('✓ Rating saved to DB (ID: ' . $stmt->insert_id . ')');
            
            error_log('Sending rating notification email');
            $email_sent = $this->send_rating_notification($customeruid, $overallrating, $improvementtext, $positivefeedback);
            error_log('Email result: ' . ($email_sent ? 'SUCCESS' : 'FAILED'));
            
            if ($overallrating <= 3) {
                error_log('Also sending ALERT email (rating <= 3)');
                $this->send_alert_email($customeruid, $overallrating, $improvementtext);
            }
        } else {
            error_log('✗ Rating save FAILED: ' . $stmt->error);
        }
    }
    
    private function extract_rating_value($rating_text) {
        if (empty($rating_text)) return NULL;
        
        if (is_numeric($rating_text)) {
            $value = intval($rating_text);
            if ($value > 5) return 5;
            if ($value < 1) return 1;
            return $value;
        }
        
        $rating_map = [
            'veľmi nespokojný' => 1, 'velmi nespokojny' => 1,
            'nespokojný' => 2, 'nespokojny' => 2,
            'neutrálny' => 3, 'neutralny' => 3,
            'spokojný' => 4, 'spokojny' => 4, 'celkom spokojný' => 4, 'celkom spokojny' => 4,
            'veľmi spokojný' => 5, 'velmi spokojny' => 5
        ];
        
        $normalized = mb_strtolower(trim($rating_text), 'UTF-8');
        
        foreach ($rating_map as $text => $value) {
            if (mb_strpos($normalized, $text) !== false) {
                return $value;
            }
        }
        
        if (preg_match('/(\d)/', $rating_text, $matches)) {
            $value = intval($matches[1]);
            if ($value > 5) return 5;
            if ($value < 1) return 1;
            return $value;
        }
        
        return NULL;
    }
    
    private function send_rating_notification($customeruid, $rating, $feedback, $positive) {
        $to = 'my@artefactum.sk';
        $subject = 'Nové Hodnotenie služby - ' . $customeruid;
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
        $bg_color = ($rating >= 4) ? '#d4edda' : (($rating == 3) ? '#fff3cd' : '#f8d7da');
        
        $customer_link = 'https://my.artefactum.sk/customer?uid=' . urlencode($customeruid);
        
        $message = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <div style='background: {$bg_color}; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <h2>📊 Nové hodnotenie od zákazníka</h2>
                    <p><strong>Zákazník:</strong> {$customeruid}</p>
                    <p><strong>Hodnotenie:</strong> <span style='font-size: 24px;'>{$stars}</span> ({$rating}/5)</p>
                    
                    " . ($feedback ? "<div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                        <strong>Čo zlepšiť:</strong><br>" . nl2br(esc_html($feedback)) . "
                    </div>" : "") . "
                    
                    " . ($positive ? "<div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>
                        <strong>Pozitívne:</strong><br>" . nl2br(esc_html($positive)) . "
                    </div>" : "") . "
                    
                    <p style='margin-top: 20px;'>
                        <a href='{$customer_link}' style='display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Zobraziť detail zákazníka</a>
                    </p>
                </div>
            </body>
            </html>
        ";
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    private function send_alert_email($customeruid, $rating, $feedback) {
        $to = 'podpora@artefactum.sk';
        $subject = '[ALERT] Nízke hodnotenie od ' . $customeruid;
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $customer_link = 'https://my.artefactum.sk/customer?uid=' . urlencode($customeruid);
        
        $message = "
            <html>
            <body>
                <div style='background: #f8d7da; border-left: 4px solid #dc3545; padding: 20px;'>
                    <h2>⚠️ NÍZKE HODNOTENIE!</h2>
                    <p><strong>Zákazník:</strong> {$customeruid}</p>
                    <p><strong>Hodnotenie:</strong> {$rating}/5</p>
                    <p><strong>Spätná väzba:</strong><br>" . nl2br(esc_html($feedback)) . "</p>
                    <p><a href='{$customer_link}' style='display: inline-block; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px;'>→ Zobraziť detail zákazníka</a></p>
                </div>
            </body>
            </html>
        ";
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    public function custom_confirmation($confirmation, $form, $entry, $ajax) {
        $overallrating = $this->extract_rating_value(rgar($entry, '6'));
        return ($overallrating >= 4) 
            ? '<div style="text-align: center; padding: 40px; background: #d4edda; border-radius: 10px;"><h2 style="color: #155724;">✓ Ďakujeme za vaše hodnotenie!</h2><p>Vaša spätná väzba je pre nás veľmi dôležitá.</p><p><img class="wp-image-4 aligncenter" src="https://artefactum.sk/arte-content/themes/artefactum-magic/images/Artefactum.svg" alt="" width="282" height="84" /></p></div>' 
            : '<div style="text-align: center; padding: 40px; background: #fff3cd; border-radius: 10px;"><h2 style="color: #856404;">Ďakujeme za vašu spätnú väzbu</h2><p>Budeme pracovať na zlepšení.</p></div>';
    }
    
    public function display_stats($atts) {
        $db = $this->get_connection();
        if (!$db) return 'DB error';
        
        $result = $db->query("SELECT COUNT(*) as total, AVG(overallrating) as avg FROM ratings WHERE submittedat >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stats = $result->fetch_assoc();
        
        return '<div>Priemerné: ' . number_format($stats['avg'], 1) . '/5 | Celkom: ' . $stats['total'] . '</div>';
    }
    
    public function ajax_generate_link() {
        check_ajax_referer('artefactum_rating_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $link = $this->generate_rating_link(
            sanitize_text_field($_POST['customeruid']),
            sanitize_text_field($_POST['servicetype']),
            sanitize_text_field($_POST['orderid'])
        );
        
        if ($link) {
            wp_send_json_success(['link' => $link]);
        } else {
            wp_send_json_error('Failed');
        }
    }
    
    public function ajax_get_debug_info() {
        if (!isset($_POST['entry_id'])) {
            wp_send_json_error('Missing entry_id');
            return;
        }
        
        $entry_id = intval($_POST['entry_id']);
        $debug_data = get_transient('rating_debug_' . $entry_id);
        
        if ($debug_data) {
            wp_send_json_success($debug_data);
        } else {
            wp_send_json_error('No debug data');
        }
    }
    
    public function add_rating_link_merge_tag($text, $form, $entry) {
        if (strpos($text, '{rating_link}') === false) return $text;
        $link = $this->generate_rating_link(rgar($entry, '3'), rgar($entry, '4'), rgar($entry, '5'));
        return str_replace('{rating_link}', $link, $text);
    }
    
    public function add_admin_menu() {
        add_menu_page('Rating System', 'Rating System', 'manage_options', 'artefactum-rating', [$this, 'admin_page'], 'dashicons-star-filled', 30);
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Artefactum Rating System</h1>
            <form id="generate-rating-form">
                <table class="form-table">
                    <tr>
                        <th>Customer UID</th>
                        <td><input type="text" id="customeruid" value="ART-001" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Service Type</th>
                        <td>
                            <select id="servicetype">
                                <option value="webdevelopment">Web Development</option>
                                <option value="seo">SEO</option>
                                <option value="support">Support</option>
                                <option value="hosting">Hosting</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Order ID</th>
                        <td><input type="text" id="orderid" class="regular-text"></td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary">Generovať Link</button>
            </form>
            
            <div id="generated-link-container" style="display:none;margin-top:20px;padding:15px;background:#f0f0f1;">
                <h3>Link:</h3>
                <input type="text" id="generated-link" readonly class="large-text">
                <p>
                    <button onclick="document.getElementById('generated-link').select();document.execCommand('copy');alert('Skopírované!');" class="button">Kopírovať</button>
                    <a id="test-link" href="" target="_blank" class="button">Otvoriť</a>
                </p>
            </div>
            
            <script>
            (function($) {
                $('#generate-rating-form').on('submit', function(e) {
                    e.preventDefault();
                    $.post(ajaxurl, {
                        action: 'generate_rating_link',
                        nonce: '<?php echo wp_create_nonce('artefactum_rating_nonce'); ?>',
                        customeruid: $('#customeruid').val(),
                        servicetype: $('#servicetype').val(),
                        orderid: $('#orderid').val()
                    }, function(r) {
                        if (r.success) {
                            $('#generated-link').val(r.data.link);
                            $('#test-link').attr('href', r.data.link);
                            $('#generated-link-container').show();
                        } else {
                            alert('Chyba: ' + r.data);
                        }
                    });
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }
    
    /**
     * AJAX: Vráti všetky zákaznícke názvy pre JavaScript
     */
    public function get_customer_names_ajax() {
		$db = $this->get_connection();
		if (!$db) {
			wp_send_json_error('Database connection failed');
			return;
		}
		
		$result = $db->query("SELECT customer_uid, company_name FROM customer_mapping");
		
		if (!$result) {
			error_log('Query failed: ' . $db->error);
			wp_send_json_error('Query failed');
			return;
		}
		
		$names = array();
		while ($row = $result->fetch_assoc()) {
			$names[$row['customer_uid']] = $row['company_name'];
		}
		
		error_log('Loaded ' . count($names) . ' customer names');
		wp_send_json_success($names);
	}
    
}

new ArtefactumRating();