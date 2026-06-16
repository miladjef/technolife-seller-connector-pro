<?php
if (!defined('ABSPATH')) { exit; }

class TLSCP_Logger {
    public function table() {
        global $wpdb;
        return $wpdb->prefix . 'tlscp_logs';
    }

    public function add($args) {
        global $wpdb;
        $defaults = array(
            'created_at' => current_time('mysql'),
            'action' => '',
            'method' => '',
            'endpoint' => '',
            'object_type' => '',
            'object_id' => '',
            'http_status' => null,
            'success' => 0,
            'message' => '',
            'request_body' => null,
            'response_body' => null,
            'retry_payload' => null,
        );
        $data = wp_parse_args($args, $defaults);
        foreach (array('request_body', 'response_body', 'retry_payload') as $key) {
            if (is_array($data[$key]) || is_object($data[$key])) {
                $data[$key] = wp_json_encode($this->mask_sensitive($data[$key]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $wpdb->insert($this->table(), $data, array('%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    public function recent($limit = 50, $success = null) {
        global $wpdb;
        $limit = max(1, min(500, absint($limit)));
        $where = '1=1';
        $params = array();
        if ($success !== null) {
            $where .= ' AND success = %d';
            $params[] = (int) $success;
        }
        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY id DESC LIMIT {$limit}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", absint($id)), ARRAY_A);
    }

    /**
     * جستجوی لاگ با فیلتر و صفحه‌بندی.
     * args: action, success ('', '0','1'), search, per_page, page
     */
    public function query($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('action' => '', 'success' => '', 'search' => '', 'per_page' => 30, 'page' => 1));
        $per_page = max(1, min(200, absint($args['per_page'])));
        $page = max(1, absint($args['page']));
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $params = array();
        if ($args['action'] !== '') { $where .= ' AND action = %s'; $params[] = sanitize_text_field($args['action']); }
        if ($args['success'] === '0' || $args['success'] === '1') { $where .= ' AND success = %d'; $params[] = (int) $args['success']; }
        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where .= ' AND (endpoint LIKE %s OR object_id LIKE %s OR message LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array($per_page, $offset))), ARRAY_A);

        return array('rows' => $rows ?: array(), 'total' => $total, 'per_page' => $per_page, 'page' => $page, 'pages' => (int) ceil($total / $per_page));
    }

    public function distinct_actions() {
        global $wpdb;
        $rows = $wpdb->get_col("SELECT DISTINCT action FROM {$this->table()} WHERE action <> '' ORDER BY action ASC");
        return is_array($rows) ? $rows : array();
    }

    public function clear_old($days = 30) {
        global $wpdb;
        $days = max(1, absint($days));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)", current_time('mysql'), $days));
    }

    private function mask_sensitive($value) {
        if (is_array($value)) {
            $masked = array();
            foreach ($value as $k => $v) {
                if (preg_match('/authorization|token|secret|api_key|encrypted/i', (string) $k)) {
                    $masked[$k] = '***';
                } else {
                    $masked[$k] = $this->mask_sensitive($v);
                }
            }
            return $masked;
        }
        if (is_object($value)) {
            return $this->mask_sensitive((array) $value);
        }
        return $value;
    }
}
