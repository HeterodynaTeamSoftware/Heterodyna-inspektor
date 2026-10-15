<?php
if (!defined('ABSPATH')) { exit; }

class HET_Inspector {

    private static $instance = null;

    /** @var string */
    public $table_events;
    /** @var string */
    public $table_relations;
    /** @var string */
    public $table_issues;
    /** @var string */
    public $table_snapshots;
	/** @var string */
	public $table_ai_reports;

	// Ochrona wydajności / anty-rekurencja
	private $capture_disabled = false;
	private $request_query_count = 0;
	private $relation_buffer = array(); // [relation_type][plugin_slug][target] => meta
	private $max_queries_per_request = 150; // twardy limit na request
	private $settings_cache = null;
	private $minute_event_count_cache = null;
	private $minute_event_count_ts = 0;

    const CRON_HOOK_TICK = 'het_inspector_tick';
    const LOCK_KEY = 'het_inspector_lock';
    const OPT_SETTINGS = 'het_inspector_settings';
	const OPT_AI_SETTINGS = 'het_inspector_ai_settings';
    const OPT_LAST_RUN = 'het_inspector_last_run';
    const OPT_SECURITY_STATE = 'het_inspector_security_state';
	const OPT_PERF_LAST = 'het_inspector_perf_last';
	const OPT_SNAPSHOT_LAST = 'het_inspector_snapshot_last';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_events    = $wpdb->prefix . 'het_inspector_events';
        $this->table_relations = $wpdb->prefix . 'het_inspector_relations';
        $this->table_issues    = $wpdb->prefix . 'het_inspector_issues';
        $this->table_snapshots = $wpdb->prefix . 'het_inspector_snapshots';
		$this->table_ai_reports = $wpdb->prefix . 'het_inspector_ai_reports';

        // Minimalny „safe mode” (nie blokuje nic automatycznie w tej wersji) – tylko loguje incydenty.
        $this->bootstrap_logging();

        // Automatyczne włączenie zbierania zapytań (SAVEQUERIES-like) – dla slow queries
        $this->maybe_enable_savequeries();

        // Admin UI
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX dla dashboardu (odświeżanie co minutę)
        add_action('wp_ajax_het_inspector_stats', array($this, 'ajax_stats'));
        add_action('wp_ajax_het_inspector_incidents', array($this, 'ajax_incidents'));
        add_action('wp_ajax_het_inspector_conflicts', array($this, 'ajax_conflicts'));
		add_action('wp_ajax_het_inspector_graph', array($this, 'ajax_graph'));

		// Snapshoty + Diff + Preflight (Pack 6)
		add_action('wp_ajax_het_inspector_snapshots_list', array($this, 'ajax_snapshots_list'));
		add_action('wp_ajax_het_inspector_snapshots_make', array($this, 'ajax_snapshots_make'));
		add_action('wp_ajax_het_inspector_snapshots_diff', array($this, 'ajax_snapshots_diff'));
		add_action('wp_ajax_het_inspector_preflight_run', array($this, 'ajax_preflight_run'));
		add_action('wp_ajax_het_inspector_preupdate_snapshot', array($this, 'ajax_preupdate_snapshot'));
		add_action('wp_ajax_het_inspector_postupdate_diff', array($this, 'ajax_postupdate_diff'));
		add_action('wp_ajax_het_inspector_safe_status', array($this, 'ajax_safe_status'));
		add_action('wp_ajax_het_inspector_safe_toggle', array($this, 'ajax_safe_toggle'));
		add_action('wp_ajax_het_inspector_safe_unblock', array($this, 'ajax_safe_unblock'));


		// Bezpieczeństwo (Pack 3)
		add_action('wp_ajax_het_inspector_security_stats', array($this, 'ajax_security_stats'));
		add_action('wp_ajax_het_inspector_security_issues', array($this, 'ajax_security_issues'));
		add_action('wp_ajax_het_inspector_security_start', array($this, 'ajax_security_start'));
		add_action('wp_ajax_het_inspector_security_progress', array($this, 'ajax_security_progress'));

		// Wydajność (Pack 4)
		add_action('wp_ajax_het_inspector_perf_stats', array($this, 'ajax_perf_stats'));
		add_action('wp_ajax_het_inspector_perf_details', array($this, 'ajax_perf_details'));

		// AI i rekomendacje (Pack 5)
		add_action('wp_ajax_het_inspector_ai_settings_get', array($this, 'ajax_ai_settings_get'));
		add_action('wp_ajax_het_inspector_ai_settings_save', array($this, 'ajax_ai_settings_save'));
		add_action('wp_ajax_het_inspector_ai_generate', array($this, 'ajax_ai_generate'));
		add_action('wp_ajax_het_inspector_ai_history', array($this, 'ajax_ai_history'));


        // Cron tick
        add_action(self::CRON_HOOK_TICK, array($this, 'cron_tick'));

        // Schedulowanie co minutę (best-effort). Dla pełnej precyzji zalecany cron systemowy (1/min).
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        $this->ensure_cron_scheduled();

        // Runtime mapowanie zapytań SQL -> tabela -> wtyczka (lekko)
        add_filter('query', array($this, 'capture_query'), 99);

		// Flush bufora relacji na koniec requestu (żeby nie robić INSERTów w środku filtra query)
		add_action('shutdown', array($this, 'shutdown_flush_relation_buffer'), 5);

		// Runtime mapowanie REST routes -> plugin (lekko)
		add_action('rest_api_init', array($this, 'capture_rest_routes'), 999);

        // Po shutdown – jeśli SAVEQUERIES włączone, log wolnych zapytań
        register_shutdown_function(array($this, 'shutdown_capture_slow_queries'));
    }

    public static function activate() {
        self::create_tables();
        self::ensure_mu_plugin();
        self::schedule_cron();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK_TICK);
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $sql = array();

        $sql[] = "CREATE TABLE {$prefix}het_inspector_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            source_type VARCHAR(32) NOT NULL DEFAULT 'system',
            source_slug VARCHAR(190) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            file_path TEXT NULL,
            line_no INT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY source_slug (source_slug),
            KEY level (level)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}het_inspector_relations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            relation_type VARCHAR(32) NOT NULL, /* table|endpoint|hook|option */
            plugin_slug VARCHAR(190) NOT NULL,
            target VARCHAR(255) NOT NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY (id),
			UNIQUE KEY uniq_rel (relation_type, plugin_slug(120), target(120)),
            KEY plugin_slug (plugin_slug),
            KEY relation_type (relation_type),
            KEY target (target)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}het_inspector_issues (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            issue_type VARCHAR(64) NOT NULL, /* conflict|security|performance|stability */
            severity VARCHAR(16) NOT NULL DEFAULT 'medium',
            plugin_slug VARCHAR(190) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL,
            details LONGTEXT NULL,
            recommendation LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY issue_type (issue_type),
            KEY severity (severity),
            KEY plugin_slug (plugin_slug),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}het_inspector_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            snapshot_type VARCHAR(32) NOT NULL, /* plugins|db|routes|hooks */
            payload LONGTEXT NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY snapshot_type (snapshot_type),
            KEY payload_hash (payload_hash)
        ) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}het_inspector_ai_reports (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			scope VARCHAR(32) NOT NULL DEFAULT 'system', /* system|plugin|custom */
			plugin_slug VARCHAR(190) NOT NULL DEFAULT '',
			provider VARCHAR(64) NOT NULL DEFAULT 'local',
			model VARCHAR(64) NOT NULL DEFAULT '',
			prompt_hash CHAR(64) NOT NULL DEFAULT '',
			result LONGTEXT NOT NULL,
			meta LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY plugin_slug (plugin_slug),
			KEY scope (scope),
			KEY provider (provider)
		) $charset_collate;";

        foreach ($sql as $q) {
            dbDelta($q);
        }

        // Domyślne ustawienia
        if (!get_option(self::OPT_SETTINGS)) {
            add_option(self::OPT_SETTINGS, array(
                'mode' => 'dev', // dev|prod
                'refresh_seconds' => 60,
                'slow_query_ms' => 300,
            'force_savequeries' => true,
                'retention_days' => 14,
                'max_events_per_tick' => 400,
                'enable_static_scan' => false,
			'security_scan_enabled' => true,
			'security_scan_interval_min' => 15,
			'security_batch_files' => 20,
			'perf_scan_interval_min' => 5,
			'snapshot_interval_min' => 30,
			'autoload_warn_kb' => 512,
			'autoload_critical_kb' => 2048,
			'cron_warn_events' => 500,
						'safe_mode_threshold' => 3,
			'safe_mode_window_sec' => 600,
'snapshot_keep' => 200,
            ), '', false);
        }

		// Domyślne ustawienia AI (oddzielnie, autoload=no)
		if (!get_option(self::OPT_AI_SETTINGS)) {
			add_option(self::OPT_AI_SETTINGS, array(
				'enabled' => false,
				'provider' => 'local', // local|http
				'endpoint_url' => '',
				'api_key' => '',
				'model' => '',
				'run_interval_min' => 30,
			), '', false);
		}
    }

    private static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK_TICK)) {
            // start za 60s
            wp_schedule_event(time() + 60, 'het_inspector_every_minute', self::CRON_HOOK_TICK);
        }
    }



    /**
     * Optional MU-plugin bootstrap.
     * Tries to create wp-content/mu-plugins/heterodyna-inspektor-mu.php.
     * If the server blocks filesystem writes, it fails silently (no fatal).
     */
    private static function ensure_mu_plugin() {
        if (!defined('WP_CONTENT_DIR')) { return; }

        $mu_dir  = WP_CONTENT_DIR . '/mu-plugins';
        $mu_file = $mu_dir . '/heterodyna-inspektor-mu.php';

        if (file_exists($mu_file)) { return; }

        if (!is_dir($mu_dir)) {
            @mkdir($mu_dir, 0755, true);
        }
        if (!is_dir($mu_dir) || !is_writable($mu_dir)) {
            return;
        }

        $plugin_main = WP_PLUGIN_DIR . '/heterodyna-inspektor/heterodyna-inspektor.php';

        $content = "<?php\n"
            . "/**\n"
            . " * Plugin Name: Heterodyna Inspektor (MU Bootstrap)\n"
            . " * Description: MU bootstrap for Heterodyna Inspektor (auto-generated).\n"
            . " */\n\n"
            . "if (defined('ABSPATH') && file_exists('" . addslashes($plugin_main) . "')) {\n"
            . "    if (!class_exists('HET_Inspector')) {\n"
            . "        include_once '" . addslashes($plugin_main) . "';\n"
            . "    }\n"
            . "}\n";

        @file_put_contents($mu_file, $content);
    }


    private function ensure_cron_scheduled() {
        self::schedule_cron();
    }

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['het_inspector_every_minute'])) {
            $schedules['het_inspector_every_minute'] = array(
                'interval' => 60,
                'display'  => 'Heterodyna Inspektor: co minutę'
            );
        }
        return $schedules;
    }

    private function bootstrap_logging() {
        // Fatal capture
        register_shutdown_function(array($this, 'shutdown_capture_fatal'));

        // PHP warnings/notices (tylko log, nie przerywa)
        set_error_handler(array($this, 'php_error_handler'));
    }

    public function php_error_handler($errno, $errstr, $errfile, $errline) {
        // Pomijamy błędy wyciszone @
        if (!(error_reporting() & $errno)) {
            return false;
        }
        $level = 'warning';
        if (in_array($errno, array(E_USER_ERROR, E_RECOVERABLE_ERROR, E_ERROR), true)) {
            $level = 'error';
        }
        $slug = $this->plugin_slug_from_path($errfile);
        $this->log_event($level, 'php', $slug, $errstr, $errfile, (int)$errline, array(
            'errno' => $errno,
        ));
        return false; // pozwól WP/innym handlerom też działać
    }

    public function shutdown_capture_fatal() {
        $e = error_get_last();
        if (!$e) { return; }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($e['type'], $fatal_types, true)) { return; }

        $slug = $this->plugin_slug_from_path($e['file']);
        $this->log_event('fatal', 'fatal', $slug, $e['message'], $e['file'], (int)$e['line'], array(
            'type' => $e['type'],
        ));
    }

    public function shutdown_capture_slow_queries() {
        // Tylko jeśli SAVEQUERIES jest aktywne (define('SAVEQUERIES', true);)
        global $wpdb;
        if (!isset($wpdb->queries) || !is_array($wpdb->queries)) {
            return;
        }
        $settings = $this->get_settings();
        $threshold_ms = max(10, (int)$settings['slow_query_ms']);

        $count = 0;
        foreach ($wpdb->queries as $q) {
            // Format: array( query, time, caller )
            $sql = isset($q[0]) ? (string)$q[0] : '';
            $time_sec = isset($q[1]) ? (float)$q[1] : 0.0;
            $caller = isset($q[2]) ? (string)$q[2] : '';
            $ms = (int)round($time_sec * 1000.0);
            if ($ms < $threshold_ms) { continue; }

            $slug = $this->plugin_slug_from_caller($caller);
            $tables = $this->extract_tables_from_sql($sql);

            $this->log_event('performance', 'slow_query', $slug, 'Wolne zapytanie SQL (' . $ms . ' ms)', null, null, array(
                'ms' => $ms,
                'caller' => $caller,
                'tables' => $tables,
                'sql' => $this->trim_sql($sql),
            ));
            $count++;
            if ($count >= 50) { break; } // zabezpieczenie
        }
    }

    public function capture_query($query) {
		// Uwaga: ten filtr potrafi odpalić się setki razy na request. Tu NIE wolno robić dodatkowych zapytań.
		if ($this->capture_disabled) { return $query; }
		if (!is_string($query) || $query === '') { return $query; }

		// Twardy limit na request (ochrona przed OOM)
		$this->request_query_count++;
		if ($this->request_query_count > $this->max_queries_per_request) {
			return $query;
		}

		// Pomijamy własne tabele (unikamy rekurencji / szumu)
		if (stripos($query, 'het_inspector_') !== false) {
			return $query;
		}

		$tables = $this->extract_tables_from_sql($query);
		if (empty($tables)) { return $query; }

		$slug = $this->plugin_slug_from_backtrace_fast();
		if ($slug === '') { $slug = 'unknown'; }

		foreach ($tables as $t) {
			$this->buffer_relation('table', $slug, $t, array('last_seen' => current_time('mysql')));
		}

		return $query;
    }

	public function shutdown_flush_relation_buffer() {
		// Flushujemy tylko jeśli coś jest w buforze
		if (empty($this->relation_buffer)) { return; }
		$this->flush_relation_buffer();
	}

	private function buffer_relation($relation_type, $plugin_slug, $target, $meta = null) {
		if ($this->capture_disabled) { return; }
		$relation_type = sanitize_key((string)$relation_type);
		$plugin_slug = sanitize_key((string)$plugin_slug);
		$target = (string)$target;
		if ($relation_type === '' || $plugin_slug === '' || $target === '') { return; }

		// Bezpieczniki pamięci — nie budujemy nieskończonego bufora
		static $buf_count = 0;
		$buf_count++;
		if ($buf_count > 3000) { return; }

		if (!isset($this->relation_buffer[$relation_type])) { $this->relation_buffer[$relation_type] = array(); }
		if (!isset($this->relation_buffer[$relation_type][$plugin_slug])) { $this->relation_buffer[$relation_type][$plugin_slug] = array(); }
		$this->relation_buffer[$relation_type][$plugin_slug][$target] = $meta;
	}

	private function flush_relation_buffer() {
		global $wpdb;
		$this->capture_disabled = true;
		try {
				$rows = array();
				$now_mysql = current_time('mysql');
				foreach ($this->relation_buffer as $rtype => $by_plugin) {
					foreach ($by_plugin as $slug => $targets) {
						foreach ($targets as $target => $meta) {
							$rows[] = array($now_mysql, (string)$rtype, (string)$slug, (string)$target, $meta);
							if (count($rows) >= 400) { break 3; } // limit porcji
						}
					}
				}

				if (empty($rows)) { return; }

				$values = array();
				$params = array();
				foreach ($rows as $r) {
					// created_at, relation_type, plugin_slug, target, meta
					$values[] = '(%s,%s,%s,%s,%s)';
					$params[] = $r[0];
					$params[] = $r[1];
					$params[] = $r[2];
					$params[] = $r[3];
					$params[] = $r[4] ? wp_json_encode($r[4]) : null;
				}

				$sql = "INSERT INTO {$this->table_relations} (created_at, relation_type, plugin_slug, target, meta) VALUES " . implode(',', $values)
					. " ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), meta = VALUES(meta)";
				$wpdb->query($wpdb->prepare($sql, $params));
		} finally {
			$this->relation_buffer = array();
			$this->capture_disabled = false;
		}
	}

    private function upsert_relation($type, $plugin_slug, $target, $meta = null) {
        global $wpdb;

        // Dedupe: jeden rekord na (type, plugin, target) – aktualizujemy meta + created_at jako last_seen
        $meta_json = $meta ? wp_json_encode($meta) : null;

        // Prosta strategia: znajdź ostatni rekord, jeśli istnieje – update, inaczej insert
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_relations} WHERE relation_type=%s AND plugin_slug=%s AND target=%s ORDER BY id DESC LIMIT 1",
            $type, $plugin_slug, $target
        ));

        if ($existing_id) {
            $wpdb->update($this->table_relations, array(
                'created_at' => current_time('mysql'),
                'meta' => $meta_json,
            ), array('id' => (int)$existing_id));
        } else {
            $wpdb->insert($this->table_relations, array(
                'created_at' => current_time('mysql'),
                'relation_type' => $type,
                'plugin_slug' => $plugin_slug,
                'target' => $target,
                'meta' => $meta_json,
            ));
        }
    }

    private function trim_sql($sql) {
        $sql = preg_replace('/\s+/', ' ', (string)$sql);
        if (strlen($sql) > 800) {
            return substr($sql, 0, 800) . '…';
        }
        return $sql;
    }

    private function extract_tables_from_sql($sql) {
        $sql_u = strtoupper($sql);
        $tables = array();

        // FROM / JOIN / UPDATE / INTO
        $patterns = array(
            '/\bFROM\s+`?([a-zA-Z0-9_]+)`?/i',
            '/\bJOIN\s+`?([a-zA-Z0-9_]+)`?/i',
            '/\bUPDATE\s+`?([a-zA-Z0-9_]+)`?/i',
            '/\bINTO\s+`?([a-zA-Z0-9_]+)`?/i',
            '/\bDELETE\s+FROM\s+`?([a-zA-Z0-9_]+)`?/i',
        );
        foreach ($patterns as $p) {
            if (preg_match_all($p, $sql, $m)) {
                foreach ($m[1] as $t) {
                    $t = trim($t);
                    if ($t !== '' && !in_array($t, $tables, true)) {
                        $tables[] = $t;
                    }
                }
            }
        }
        // ogranicz
        if (count($tables) > 10) {
            $tables = array_slice($tables, 0, 10);
        }
        return $tables;
    }

	private function plugin_slug_from_backtrace_fast() {
		// debug_backtrace bywa bardzo kosztowny; minimalizujemy głębokość i omijamy core WP.
		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
		foreach ($bt as $frame) {
			if (empty($frame['file'])) { continue; }
			$fp = (string)$frame['file'];
			// Pomijamy core i sam Inspektor, szukamy pierwszego sensownego pliku z wtyczki
			if (strpos($fp, WP_CONTENT_DIR . '/plugins/heterodyna-inspektor/') !== false) { continue; }
			if (strpos($fp, ABSPATH . 'wp-includes/') !== false) { continue; }
			if (strpos($fp, ABSPATH . 'wp-admin/') !== false) { continue; }
			$slug = $this->plugin_slug_from_path($fp);
			if ($slug !== '') { return $slug; }
		}
		return '';
	}

	// Kompatybilność wstecz – jeśli coś wywoła starą metodę
	private function plugin_slug_from_backtrace() {
		return $this->plugin_slug_from_backtrace_fast();
	}

    private function plugin_slug_from_path($path) {
        $path = (string)$path;
        $pos = strpos($path, WP_CONTENT_DIR . '/plugins/');
        if ($pos === false) {
            // MU-plugins?
            $pos2 = strpos($path, WP_CONTENT_DIR . '/mu-plugins/');
            if ($pos2 !== false) { return 'mu-plugins'; }
            return '';
        }
        $rest = substr($path, $pos + strlen(WP_CONTENT_DIR . '/plugins/'));
        $parts = explode('/', $rest);
        $slug = isset($parts[0]) ? sanitize_key($parts[0]) : '';
        return $slug;
    }

	private function plugin_slug_from_callback($callback) {
		if (!$callback) { return ''; }
		try {
			// string function
			if (is_string($callback) && function_exists($callback)) {
				$rf = new ReflectionFunction($callback);
				return $this->plugin_slug_from_path($rf->getFileName());
			}
			// array(object, method) or array(class, method)
			if (is_array($callback) && count($callback) === 2) {
				$obj = $callback[0];
				if (is_object($obj)) {
					$rc = new ReflectionClass($obj);
					return $this->plugin_slug_from_path($rc->getFileName());
				}
				if (is_string($obj) && class_exists($obj)) {
					$rc = new ReflectionClass($obj);
					return $this->plugin_slug_from_path($rc->getFileName());
				}
			}
			// closure
			if ($callback instanceof Closure) {
				$rf = new ReflectionFunction($callback);
				return $this->plugin_slug_from_path($rf->getFileName());
			}
		} catch (Throwable $e) {
			return '';
		}
		return '';
	}

    private function plugin_slug_from_caller($caller) {
        // Caller by SAVEQUERIES wygląda np.: "require_once('.../wp-content/plugins/xyz/file.php')"
        if (preg_match('#wp-content/plugins/([^/]+)/#', $caller, $m)) {
            return sanitize_key($m[1]);
        }
        if (strpos($caller, 'wp-content/mu-plugins/') !== false) {
            return 'mu-plugins';
        }
        return 'unknown';
    }


	private function maybe_enable_savequeries() {
		$settings = $this->get_settings();
		if (empty($settings['force_savequeries'])) {
			return;
		}
		global $wpdb;
		if (!is_object($wpdb)) {
			return;
		}
		// Nie możemy zdefiniować stałej SAVEQUERIES po starcie WP,
		// ale możemy włączyć mechanizm w obiekcie $wpdb (działa dla kolejnych zapytań).
		$wpdb->save_queries = true;
		if (!isset($wpdb->queries) || !is_array($wpdb->queries)) {
			$wpdb->queries = array();
		}
	}

    private function get_settings() {
		if (is_array($this->settings_cache)) {
			return $this->settings_cache;
		}
		$s = get_option(self::OPT_SETTINGS, array());
		if (!is_array($s)) { $s = array(); }
        $defaults = array(
            'mode' => 'dev',
            'refresh_seconds' => 60,
            'slow_query_ms' => 300,
            'force_savequeries' => true,
            'retention_days' => 14,
            'max_events_per_tick' => 400,
            'enable_static_scan' => false,
			'security_scan_enabled' => true,
			'security_scan_interval_min' => 15,
			'security_batch_files' => 20,
			'perf_scan_interval_min' => 5,
			'autoload_warn_kb' => 512,
			'autoload_critical_kb' => 2048,
			'cron_warn_events' => 500,
        			'safe_mode_threshold' => 3,
			'safe_mode_window_sec' => 600,
);
		$this->settings_cache = array_merge($defaults, $s);
		return $this->settings_cache;
    }

	private function get_ai_settings() {
		$defaults = array(
			'enabled' => false,
			'provider' => 'local',
			'endpoint_url' => '',
			'api_key' => '',
			'model' => '',
			'run_interval_min' => 30,
		);
		$opt = get_option(self::OPT_AI_SETTINGS, array());
		if (!is_array($opt)) { $opt = array(); }
		return array_merge($defaults, $opt);
	}

	private function update_ai_settings(array $new) {
		$cur = $this->get_ai_settings();
		$merged = array_merge($cur, $new);
		// nie autoloadujemy kluczy – tabela options ma to już jako autoload=no, ale zachowujemy porządek
		update_option(self::OPT_AI_SETTINGS, $merged, false);
		return $merged;
	}

    private function acquire_lock($ttl = 55) {
        $key = self::LOCK_KEY;
        if (get_transient($key)) { return false; }
        set_transient($key, 1, $ttl);
        return true;
    }

    private function release_lock() {
        delete_transient(self::LOCK_KEY);
    }

    public function cron_tick() {
        if (!$this->acquire_lock()) {
            return;
        }
        try {
            $settings = $this->get_settings();

            // Safe Mode (dla MU-plugin): progi eskalacji
            update_option('het_inspector_safe_threshold', (int)$settings['safe_mode_threshold'], false);
            update_option('het_inspector_safe_window_sec', (int)$settings['safe_mode_window_sec'], false);

            // 1) Retencja
            $this->apply_retention((int)$settings['retention_days']);

            // 2) Snapshot aktywnych wtyczek + szybkie konflikty
            $this->snapshot_plugins_and_conflicts();

			// 3) Snapshot hooków (co ~5 minut) + relacje hook -> wtyczka
			$this->snapshot_hooks_and_relations();

			// 3b) Snapshoty systemowe: schemat DB + diff (co X minut)
			$this->snapshot_system_tick();


            // 4) Bezpieczeństwo: skan (w porcjach, w tle)
            $this->security_scan_tick();

			// 4b) Wydajność: szybki audyt (co kilka minut)
			$this->performance_tick();

			// 4c) AI: rekomendacje (zgodnie z interwałem)
			$this->ai_tick();

            // 5) Ostatni tick
            update_option(self::OPT_LAST_RUN, current_time('mysql'), false);
        } catch (Throwable $e) {
            $this->log_event('error', 'inspector', 'heterodyna-inspektor', 'Błąd tick: ' . $e->getMessage(), $e->getFile(), (int)$e->getLine(), array(
                'trace' => $e->getTraceAsString(),
            ));
        } finally {
            $this->release_lock();
        }
    }

    private function apply_retention($days) {
        $days = max(1, min(90, (int)$days));
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_events} WHERE created_at < %s", $cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_issues} WHERE created_at < %s", $cutoff));
        // relations i snapshots trzymamy dłużej (można dodać osobną retencję później)
    }

    private function snapshot_plugins_and_conflicts() {
        // Snapshot: aktywne wtyczki + wersje
        $active = (array) get_option('active_plugins', array());
        $plugins = array();
        foreach ($active as $p) {
            $abs = WP_PLUGIN_DIR . '/' . $p;
            $data = array(
                'plugin' => $p,
                'exists' => file_exists($abs),
                'version' => null,
                'name' => null,
            );
            if (file_exists($abs)) {
                $headers = get_file_data($abs, array(
                    'Name' => 'Plugin Name',
                    'Version' => 'Version',
                ));
                $data['name'] = isset($headers['Name']) ? $headers['Name'] : null;
                $data['version'] = isset($headers['Version']) ? $headers['Version'] : null;
            }
            $plugins[] = $data;
        }
        $this->store_snapshot('plugins', $plugins);

        // Konflikty: duplikaty „wariantów” tej samej wtyczki (heurystyka)
        $conflicts = $this->detect_duplicate_variants();
        $this->store_snapshot('conflicts', $conflicts);

        // Zapisz issues (odśwież – w tej wersji: usuń stare conflict issues i wstaw nowe)
        $this->refresh_conflict_issues($conflicts);
    }

    private function store_snapshot($type, $payload) {
        global $wpdb;
        $json = wp_json_encode($payload);
        $hash = hash('sha256', $json);

        $last_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT payload_hash FROM {$this->table_snapshots} WHERE snapshot_type=%s ORDER BY id DESC LIMIT 1",
            $type
        ));
        if ($last_hash === $hash) {
            return; // nic się nie zmieniło
        }

        $wpdb->insert($this->table_snapshots, array(
            'created_at' => current_time('mysql'),
            'snapshot_type' => $type,
            'payload' => $json,
            'payload_hash' => $hash,
        ));
    }

	// -----------------------------
	// Wydajność (Pack 4) — snapshot + issues
	// -----------------------------
	private function get_last_perf_snapshot() {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT payload FROM {$this->table_snapshots} WHERE snapshot_type=%s ORDER BY id DESC LIMIT 1",
			'perf'
		), ARRAY_A);
		if (!$row || empty($row['payload'])) { return array(); }
		$decoded = json_decode((string)$row['payload'], true);
		return is_array($decoded) ? $decoded : array();
	}

	private function performance_tick() {
		$settings = $this->get_settings();
		$interval_min = max(1, min(60, (int)$settings['perf_scan_interval_min']));
		$last = (int) get_option(self::OPT_PERF_LAST, 0);
		$now = time();
		if ($last && ($now - $last) < ($interval_min * 60)) {
			return;
		}

		$payload = array(
			'autoload' => $this->collect_autoload_stats(),
			'cron' => $this->collect_cron_stats(),
			'savequeries' => (defined('SAVEQUERIES') && SAVEQUERIES) ? 1 : 0,
		);

		$this->store_snapshot('perf', $payload);
		$this->refresh_performance_issues($payload);
		update_option(self::OPT_PERF_LAST, $now, false);
	}

	private function collect_autoload_stats() {
		global $wpdb;
		$total = (int) $wpdb->get_var("SELECT IFNULL(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload='yes'");
		$rows = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload='yes' ORDER BY bytes DESC LIMIT 30", ARRAY_A);
		$out = array(
			'total_kb' => (int) round($total / 1024),
			'top' => array(),
		);
		foreach ((array)$rows as $r) {
			$out['top'][] = array(
				'option_name' => (string)$r['option_name'],
				'kb' => (int) round(((int)$r['bytes']) / 1024),
			);
		}
		return $out;
	}

	private function collect_cron_stats() {
		$cron = get_option('cron');
		$hooks = 0;
		$events = 0;
		if (is_array($cron)) {
			foreach ($cron as $ts => $data) {
				if (!is_array($data)) { continue; }
				foreach ($data as $hook => $schedules) {
					$hooks++;
					if (is_array($schedules)) {
						$events += count($schedules);
					}
				}
			}
		}
		return array(
			'hooks' => $hooks,
			'events' => $events,
		);
	}

	private function refresh_performance_issues($payload) {
		global $wpdb;
		$cutoff = gmdate('Y-m-d H:i:s', time() - (2 * 86400));
		$wpdb->query($wpdb->prepare("DELETE FROM {$this->table_issues} WHERE issue_type='performance' AND created_at >= %s", $cutoff));

		$settings = $this->get_settings();
		$warn_kb = (int)$settings['autoload_warn_kb'];
		$crit_kb = (int)$settings['autoload_critical_kb'];
		$cron_warn = (int)$settings['cron_warn_events'];
		$autoload_total = isset($payload['autoload']['total_kb']) ? (int)$payload['autoload']['total_kb'] : 0;
		$cron_events = isset($payload['cron']['events']) ? (int)$payload['cron']['events'] : 0;

		if ($autoload_total >= $warn_kb) {
			$sev = ($autoload_total >= $crit_kb) ? 'high' : 'medium';
			$title = 'Duże autoload w wp_options (' . $autoload_total . ' KB)';
			$rec = 'Rozważ ustawienie autoload=no dla ciężkich opcji (np. cache/telemetria), przeniesienie dużych payloadów do własnych tabel lub transients. TOP opcji w panelu „Wydajność”.';
			$wpdb->insert($this->table_issues, array(
				'created_at' => current_time('mysql'),
				'issue_type' => 'performance',
				'severity' => $sev,
				'plugin_slug' => 'wp_options',
				'title' => $title,
				'details' => wp_json_encode($payload['autoload']),
				'recommendation' => $rec,
			));
		}

		if ($cron_events >= $cron_warn) {
			$title = 'Bardzo dużo eventów WP-Cron (' . $cron_events . ')';
			$rec = 'Ogranicz częstotliwość zadań, łącz ticki, wprowadź lock i sampling. Nadmiar WP-Cron potrafi powodować lagi i kaskady błędów.';
			$wpdb->insert($this->table_issues, array(
				'created_at' => current_time('mysql'),
				'issue_type' => 'performance',
				'severity' => 'medium',
				'plugin_slug' => 'wp-cron',
				'title' => $title,
				'details' => wp_json_encode($payload['cron']),
				'recommendation' => $rec,
			));
		}
	}

    private function detect_duplicate_variants() {
        // Heurystyka: dwa plugin slugs zawierające ten sam bazowy rdzeń (np. heterodyna-seo oraz heterodyna-seo-v06-...)
        $all = get_plugins();
        $by_core = array();

        foreach ($all as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') { $slug = basename($file, '.php'); }
            $core = preg_replace('/-v\d+.*$/i', '', $slug); // usuń -v06-...
            // Dodatkowo typowe sufiksy
            $core = preg_replace('/-(upgrade|hotfix|client|server|ready|beta|dev).*$/i', '', $core);
            $core = sanitize_key($core);

            if (!isset($by_core[$core])) { $by_core[$core] = array(); }
            $by_core[$core][] = array(
                'slug' => $slug,
                'file' => $file,
                'name' => isset($data['Name']) ? $data['Name'] : '',
                'version' => isset($data['Version']) ? $data['Version'] : '',
            );
        }

        $conflicts = array();
        foreach ($by_core as $core => $items) {
            if (count($items) <= 1) { continue; }
            // Filtr: tylko sensowne “core”
            if (strlen($core) < 6) { continue; }
            $conflicts[] = array(
                'core' => $core,
                'variants' => $items,
                'hint' => 'Wykryto kilka wariantów tej samej wtyczki. Zostaw jedną (kanoniczną), resztę usuń lub scal.',
            );
        }
        return $conflicts;
    }

    private function refresh_conflict_issues($conflicts) {
        global $wpdb;
        // Usuń stare conflict issues z ostatnich 2 dni (prosty refresh)
        $cutoff = gmdate('Y-m-d H:i:s', time() - (2 * 86400));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_issues} WHERE issue_type='conflict' AND created_at >= %s", $cutoff));

        foreach ($conflicts as $c) {
            $title = 'Duplikat wariantów: ' . $c['core'];
            $details = wp_json_encode($c);
			$rec = 'Zostaw jedną wersję wtyczki "' . $c['core'] . '". Jeśli to build typu upgrade – scalić zmiany do kanonicznego sluga i utrzymywać tylko jedną kopię w /plugins/.';
            $wpdb->insert($this->table_issues, array(
                'created_at' => current_time('mysql'),
                'issue_type' => 'conflict',
                'severity' => 'high',
                'plugin_slug' => $c['core'],
                'title' => $title,
                'details' => $details,
                'recommendation' => $rec,
            ));
        }
    }

	private function snapshot_hooks_and_relations() {
		// Nie róbmy tego co minutę na siłę. Co 5 minut w zupełności wystarczy.
		if (get_transient('het_inspector_hooks_lock')) {
			return;
		}
		set_transient('het_inspector_hooks_lock', 1, 300);

		global $wp_filter;
		if (!is_array($wp_filter) || empty($wp_filter)) { return; }

		$summary = array();
		$hook_limit = 220;
		$cb_limit = 2500;
		$cb_count = 0;
		$hook_count = 0;

		foreach ($wp_filter as $hook_name => $hook_obj) {
			if ($hook_count >= $hook_limit) { break; }
			$hook_count++;
			$plugins = array();
			// WP_Hook
			$callbacks = null;
			if (is_object($hook_obj) && isset($hook_obj->callbacks) && is_array($hook_obj->callbacks)) {
				$callbacks = $hook_obj->callbacks;
			} elseif (is_array($hook_obj)) {
				$callbacks = $hook_obj;
			}
			if (!is_array($callbacks)) { continue; }

			foreach ($callbacks as $prio => $items) {
				if (!is_array($items)) { continue; }
				foreach ($items as $id => $item) {
					if ($cb_count >= $cb_limit) { break 3; }
					$cb_count++;
					$cb = isset($item['function']) ? $item['function'] : null;
					$slug = $this->plugin_slug_from_callback($cb);
					if ($slug === '') { continue; }
					$plugins[$slug] = true;
				}
			}

			$plist = array_keys($plugins);
			if (!empty($plist)) {
				sort($plist);
				$summary[] = array('hook' => (string)$hook_name, 'plugins' => $plist);
				foreach ($plist as $slug) {
					$this->buffer_relation('hook', $slug, (string)$hook_name, array('last_seen' => current_time('mysql')));
				}
			}
		}

		// Snapshot do diffów
		$this->store_snapshot('hooks', array(
			'captured_at' => current_time('mysql'),
			'hooks' => $summary,
			'limits' => array('hook_limit' => $hook_limit, 'cb_limit' => $cb_limit),
		));
	}


	// -----------------------------
	// Snapshoty systemowe + diff (Pack 6)
	// -----------------------------
	private function snapshot_system_tick() {
		$settings = $this->get_settings();
		$interval_min = max(5, min(240, (int)($settings['snapshot_interval_min'] ?? 30)));
		$last = (int) get_option(self::OPT_SNAPSHOT_LAST, 0);
		$now = time();
		if ($last && ($now - $last) < ($interval_min * 60)) {
			return;
		}

		// Snapshot schematu DB (tylko tabele niestandardowe, żeby nie mielić całego WP)
		$db = $this->collect_custom_tables_schema();
		$this->store_snapshot('db', $db);

		// Snapshot podsumowania relacji (plugin -> (tables/endpoints/hooks) counts)
		$summary = $this->collect_relations_summary();
		$this->store_snapshot('summary', $summary);

		// Snapshot routów REST (na bazie relacji endpoint)
		$routes = $this->collect_endpoints_snapshot();
		$this->store_snapshot('routes', $routes);

		update_option(self::OPT_SNAPSHOT_LAST, $now, false);
	}

	private function collect_custom_tables_schema() {
		global $wpdb;

		// Lista standardowych tabel WP (pomijamy)
		$core = array(
			$wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta,
			$wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships,
			$wpdb->termmeta, $wpdb->users, $wpdb->usermeta,
			$wpdb->options, $wpdb->links,
		);
		$core = array_map('strval', $core);

		$tables = $wpdb->get_col("SHOW TABLES");
		$out = array();
		$limit = 120; // bezpiecznik
		foreach ((array)$tables as $t) {
			$t = (string)$t;
			if (in_array($t, $core, true)) { continue; }
			// Pomijamy też większość tabel cache'owych / tymczasowych, jeśli ktoś je ma
			if (stripos($t, 'wp_cache') !== false) { continue; }

			$create = $wpdb->get_row("SHOW CREATE TABLE `$t`", ARRAY_N);
			$ddl = is_array($create) && isset($create[1]) ? (string)$create[1] : '';
			if ($ddl === '') { continue; }

			$out[$t] = array(
				'hash' => hash('sha256', $ddl),
				'ddl'  => $ddl,
			);

			$limit--;
			if ($limit <= 0) { break; }
		}
		return $out;
	}

	private function collect_relations_summary() {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT plugin_slug, relation_type, COUNT(*) AS cnt FROM {$this->table_relations} GROUP BY plugin_slug, relation_type", ARRAY_A);
		$out = array();
		foreach ((array)$rows as $r) {
			$ps = (string)$r['plugin_slug'];
			$rt = (string)$r['relation_type'];
			if (!isset($out[$ps])) { $out[$ps] = array('table'=>0,'endpoint'=>0,'hook'=>0,'option'=>0); }
			$out[$ps][$rt] = (int)$r['cnt'];
		}
		ksort($out);
		return $out;
	}

	private function collect_endpoints_snapshot() {
		global $wpdb;
		// Budujemy snapshot routów REST na podstawie relacji typu 'endpoint'
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT plugin_slug, target, meta FROM {$this->table_relations} WHERE relation_type=%s ORDER BY plugin_slug ASC, target ASC",
			'endpoint'
		), ARRAY_A);
		$out = array();
		foreach ((array)$rows as $r) {
			$ps = (string)($r['plugin_slug'] ?? '');
			$t  = (string)($r['target'] ?? '');
			$meta = array();
			if (!empty($r['meta'])) {
				$tmp = json_decode((string)$r['meta'], true);
				if (is_array($tmp)) { $meta = $tmp; }
			}
			if ($ps === '' || $t === '') { continue; }
			if (!isset($out[$ps])) { $out[$ps] = array(); }
			$out[$ps][] = array('route' => $t, 'meta' => $meta);
		}
		return $out;
	}


	private function get_latest_snapshot_row($type) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT id, created_at, payload, payload_hash FROM {$this->table_snapshots} WHERE snapshot_type=%s ORDER BY id DESC LIMIT 1", $type), ARRAY_A);
	}

	private function get_snapshot_row_by_id($id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT id, created_at, snapshot_type, payload, payload_hash FROM {$this->table_snapshots} WHERE id=%d", (int)$id), ARRAY_A);
	}

	private function diff_snapshots_payload($a_payload, $b_payload) {
		$a = json_decode((string)$a_payload, true);
		$b = json_decode((string)$b_payload, true);
		if (!is_array($a)) { $a = array(); }
		if (!is_array($b)) { $b = array(); }

		// Dla db: klucze to nazwy tabel
		$added = array();
		$removed = array();
		$changed = array();

		$ak = array_keys($a);
		$bk = array_keys($b);

		foreach ($bk as $k) {
			if (!array_key_exists($k, $a)) { $added[] = $k; }
			else {
				$ah = is_array($a[$k]) && isset($a[$k]['hash']) ? $a[$k]['hash'] : md5(wp_json_encode($a[$k]));
				$bh = is_array($b[$k]) && isset($b[$k]['hash']) ? $b[$k]['hash'] : md5(wp_json_encode($b[$k]));
				if ($ah !== $bh) { $changed[] = $k; }
			}
		}
		foreach ($ak as $k) {
			if (!array_key_exists($k, $b)) { $removed[] = $k; }
		}

		return array(
			'added' => $added,
			'removed' => $removed,
			'changed' => $changed,
		);
	}

    public function log_event($level, $source_type, $source_slug, $message, $file_path = null, $line_no = null, $context = null) {
        global $wpdb;
		$settings = $this->get_settings();
		$max = max(50, (int)$settings['max_events_per_tick']);

		// Soft rate limit (cache na minutę) – nie odpalamy COUNT() przy każdym warningu
		$now = time();
		if ($this->minute_event_count_cache === null || ($now - (int)$this->minute_event_count_ts) >= 60) {
			$this->minute_event_count_ts = $now;
			$since = gmdate('Y-m-d H:i:s', $now - 60);
			// UWAGA: created_at jest w czasie lokalnym WP; używamy current_time w INSERT, ale tu cache jest tylko orientacyjny
			$this->capture_disabled = true;
			$this->minute_event_count_cache = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_events} WHERE created_at >= %s", $since));
			$this->capture_disabled = false;
		}
		if ((int)$this->minute_event_count_cache >= $max && $level !== 'fatal') {
			return;
		}

		$this->capture_disabled = true;
		$wpdb->insert($this->table_events, array(
            'created_at' => current_time('mysql'),
            'level' => (string)$level,
            'source_type' => (string)$source_type,
            'source_slug' => (string)$source_slug,
            'message' => (string)$message,
            'file_path' => $file_path ? (string)$file_path : null,
            'line_no' => $line_no !== null ? (int)$line_no : null,
            'context' => $context ? wp_json_encode($context) : null,
        ));
		$this->capture_disabled = false;
		$this->minute_event_count_cache = (int)$this->minute_event_count_cache + 1;
    }

    public function admin_menu() {
        add_menu_page(
            'Heterodyna Inspektor',
            'Inspektor',
            'manage_options',
            'het-inspektor',
            array($this, 'render_page'),
            'dashicons-shield-alt',
            58
        );

		add_submenu_page(
			'het-inspektor',
			'Eksplorator zależności',
			'Eksplorator',
			'manage_options',
			'het-inspektor-explorer',
			array($this, 'render_explorer_page')
		);


		add_submenu_page(
			'het-inspektor',
			'Bezpieczeństwo',
			'Bezpieczeństwo',
			'manage_options',
			'het-inspektor-security',
			array($this, 'render_security_page')
		);

		add_submenu_page(
			'het-inspektor',
			'Wydajność i Google',
			'Wydajność',
			'manage_options',
			'het-inspektor-performance',
			array($this, 'render_performance_page')
		);


		add_submenu_page(
			'het-inspektor',
			'Snapshoty i Diff',
			'Snapshoty',
			'manage_options',
			'het-inspektor-snapshots',
			array($this, 'render_snapshots_page')
		);

		add_submenu_page(
			'het-inspektor',
			'Preflight (przed aktualizacją)',
			'Preflight',
			'manage_options',
			'het-inspektor-preflight',
			array($this, 'render_preflight_page')
		);

		add_submenu_page(
			'het-inspektor',
			'AI i rekomendacje',
			'AI',
			'manage_options',
			'het-inspektor-ai',
			array($this, 'render_ai_page')
		);
    }

    public function enqueue_admin_assets($hook) {
        $allowed = array('toplevel_page_het-inspektor', 'inspektor_page_het-inspektor-explorer', 'inspektor_page_het-inspektor-security', 'inspektor_page_het-inspektor-performance', 'inspektor_page_het-inspektor-ai');
        if (!in_array($hook, $allowed, true)) { return; }

        wp_enqueue_style('het-inspector-admin', HET_INSPECTOR_URL . 'assets/admin.css', array(), HET_INSPECTOR_VERSION);
        wp_enqueue_script('het-inspector-admin', HET_INSPECTOR_URL . 'assets/admin.js', array('jquery'), HET_INSPECTOR_VERSION, true);

		// Cytoscape (2D) — graf zależności. Ładujemy tylko w Eksploratorze.
		if ($hook === 'inspektor_page_het-inspektor-explorer') {
			wp_enqueue_script('het-inspector-cytoscape', 'https://unpkg.com/cytoscape@3.30.2/dist/cytoscape.min.js', array(), HET_INSPECTOR_VERSION, true);
		}

        wp_localize_script('het-inspector-admin', 'HET_INSPECTOR', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('het_inspector_nonce'),
            'refresh' => (int)$this->get_settings()['refresh_seconds'],
        ));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        $last_run = get_option(self::OPT_LAST_RUN, '');
        ?>
        <div class="wrap het-inspector">
            <div class="het-inspector__topbar">
                <div>
                    <h1>Heterodyna Inspektor</h1>
                    <div class="het-inspector__sub">
                        Wersja: <strong><?php echo esc_html(HET_INSPECTOR_VERSION); ?></strong>
                        &nbsp;•&nbsp; Ostatni tick: <strong id="het-inspector-last-run"><?php echo $last_run ? esc_html($last_run) : '—'; ?></strong>
                        &nbsp;•&nbsp; Odświeżanie: <strong><?php echo (int)$this->get_settings()['refresh_seconds']; ?>s</strong>
                    </div>
                </div>
                <div class="het-inspector__topbar-actions">
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=het-inspektor&fullscreen=1')); ?>" target="_blank">Pełny ekran</a>
                </div>
            </div>

            <div class="het-grid">
                <div class="het-card">
                    <div class="het-card__head">
                        <h2>Zdrowie systemu</h2>
                        <div class="het-card__meta" id="het-inspector-health-meta">Ładowanie…</div>
                    </div>
                    <div class="het-card__content">
                        <div class="het-metrics">
                            <div class="het-metric">
                                <div class="het-metric__label">Fatale (24h)</div>
                                <div class="het-metric__value" id="m-fatal">—</div>
                            </div>
                            <div class="het-metric">
                                <div class="het-metric__label">Błędy (24h)</div>
                                <div class="het-metric__value" id="m-error">—</div>
                            </div>
                            <div class="het-metric">
                                <div class="het-metric__label">Ostrzeżenia (24h)</div>
                                <div class="het-metric__value" id="m-warning">—</div>
                            </div>
                            <div class="het-metric">
                                <div class="het-metric__label">Konflikty</div>
                                <div class="het-metric__value" id="m-conflicts">—</div>
                            </div>
                        </div>
                        <p class="description">
                            Tryb dev: w tle zbieramy relacje SQL→tabele, incydenty PHP/fatal, oraz wykrywamy duplikaty wariantów wtyczek.
                            Inspektor automatycznie włącza zbieranie zapytań (SAVEQUERIES-like) od momentu załadowania wtyczek. Dla pełnego pokrycia od samego startu dodaj też <code>define('SAVEQUERIES', true);</code> w <code>wp-config.php</code>.
                        </p>
                    </div>
                </div>

                <div class="het-card">
                    <div class="het-card__head">
                        <h2>Konflikty i duplikaty</h2>
                        <div class="het-card__meta">Automatyczne sugestie</div>
                    </div>
                    <div class="het-card__content">
                        <div id="het-conflicts" class="het-table-wrap">Ładowanie…</div>
                    </div>
                </div>

                <div class="het-card">
                    <div class="het-card__head">
                        <h2>Incydenty</h2>
                        <div class="het-card__meta">Ostatnie wpisy (fatale/błędy/ostrzeżenia)</div>
                    </div>
                    <div class="het-card__content">
                        <div id="het-incidents" class="het-table-wrap">Ładowanie…</div>
                    </div>
                </div>
            </div>

            
<div class="het-card het-card--wide">
    <div class="het-card__head">
        <h2>Tryb awaryjny (Safe Mode)</h2>
        <div class="het-card__meta">MU-plugin może automatycznie zablokować wtyczkę po serii fatal errors</div>
    </div>
    <div class="het-card__content">
        <div id="het-safe-status">Ładowanie…</div>
        <div class="het-row" style="margin-top:10px">
            <button class="button button-secondary" id="het-safe-toggle">Włącz / Wyłącz</button>
            <a class="button button-link" href="<?php echo esc_url(admin_url()); ?>?het_safe=1" target="_blank" rel="noopener">Wejdź do panelu z parametrem het_safe=1</a>
        </div>
        <div id="het-safe-blocked" style="margin-top:12px"></div>
    </div>
</div>

<div class="het-card het-card--wide">
                <div class="het-card__head">
                    <h2>Roadmapa (globalna)</h2>
                    <div class="het-card__meta">Stan + kolejne kroki</div>
                </div>
                <div class="het-card__content">
                    <ul class="het-roadmap">
                        <li><strong>[DONE]</strong> Rejestr incydentów: fatal + PHP warnings/notices + kontekst (plik/linia + plugin slug).</li>
                        <li><strong>[DONE]</strong> Runtime mapowanie SQL → tabele → wtyczka (backtrace).</li>
                        <li><strong>[DONE]</strong> Tick co minutę (WP-Cron) + lock + retencja logów.</li>
                        <li><strong>[DONE]</strong> Dashboard + auto-odświeżanie co minutę (AJAX).</li>
                        <li><strong>[DONE]</strong> Wykrywanie duplikatów wariantów wtyczek (heurystyka) + sugestie.</li>
						<li><strong>[DONE]</strong> Rejestracja REST routes (rest_api_init) + przypisanie do wtyczki (callback/backtrace).</li>
						<li><strong>[DONE]</strong> Audyt hooków z $wp_filter + przypisanie callbacków do wtyczek (snapshot co ~5 min).</li>
						<li><strong>[DONE]</strong> Graf zależności (2D Cytoscape, opcjonalnie 3D) w osobnym panelu „Eksplorator”.</li>
						<li><strong>[DONE]</strong> Motyw grafu (auto/jasny/ciemny/kontrast) + czytelne etykiety w 2D/3D.</li>
						<li><strong>[DONE]</strong> Skan bezpieczeństwa (w tle, porcjami): REST permission_callback (heur.), SQL prepare (heur.), nonce/AJAX (heur.), wejście $_GET/$_POST, eval/base64, upload.</li>
					<li><strong>[DONE]</strong> Wydajność: autoload options (TOP), statystyki WP-Cron (liczba eventów/hooków), podgląd slow queries (SAVEQUERIES), sugerowane progi ryzyka.</li>
                        <li><strong>[TODO]</strong> Tryb awaryjny: safe mode dla admina + automatyczne odcięcie problematycznych hooków po serii fatalów.</li>
					<li><strong>[DONE]</strong> Warstwa AI: panel AI + generowanie rekomendacji (lokalne heurystyki lub HTTP endpoint) + historia raportów + opcjonalne generowanie w tle.</li>
						<li><strong>[DONE]</strong> Snapshoty + Diff: zapis stanu (plugins/conflicts/hooks/routes/db/summary) + porównanie między snapshotami.</li>
						<li><strong>[DONE]</strong> Preflight: szybki test przed aktualizacją (duplikaty, cron, autoload, świeże fatale) + rekomendacje.</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

	// -----------------------------
	// Snapshoty (UI) + Preflight (Pack 6)
	// -----------------------------
	public function render_snapshots_page() {
		if (!current_user_can('manage_options')) { wp_die('Brak uprawnień.'); }
		?>
		<div class="wrap het-inspector">
			<div class="het-inspector__topbar">
				<div>
					<h1>Snapshoty i Diff</h1>
					<div class="het-inspector__sub">Porównuj stan systemu między snapshotami (wtyczki / konflikty / hooki / REST / DB / podsumowanie).</div>
				</div>
				<div class="het-inspector__topbar-actions">
					<button class="button button-primary" id="het-snap-make">Zrób snapshot teraz</button>
				</div>
			</div>

			<div class="het-grid">
				<div class="het-card">
					<div class="het-card__head">
						<h2>Lista snapshotów</h2>
						<div class="het-card__meta">Ostatnie wpisy</div>
					</div>
					<div class="het-card__content">
						<div class="het-row">
							<label>Typ:</label>
							<select id="het-snap-type">
								<option value="plugins">plugins</option>
								<option value="conflicts">conflicts</option>
								<option value="hooks">hooks</option>
								<option value="routes">routes</option>
								<option value="db">db</option>
								<option value="summary">summary</option>
								<option value="perf">perf</option>
								<option value="security">security</option>
							</select>
							<button class="button" id="het-snap-refresh">Odśwież</button>
						</div>
						<div id="het-snapshots-list" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>

				<div class="het-card">
					<div class="het-card__head">
						<h2>Diff</h2>
						<div class="het-card__meta">Wybierz 2 snapshoty tego samego typu</div>
					</div>
					<div class="het-card__content">
						<div class="het-row">
							<label>A:</label>
							<select id="het-diff-a"></select>
							<label>B:</label>
							<select id="het-diff-b"></select>
							<button class="button button-secondary" id="het-diff-run">Pokaż diff</button>
						</div>
						<div id="het-snap-diff" class="het-table-wrap">—</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_preflight_page() {
		if (!current_user_can('manage_options')) { wp_die('Brak uprawnień.'); }
		?>
		<div class="wrap het-inspector">
			<div class="het-inspector__topbar">
				<div>
					<h1>Preflight (przed aktualizacją)</h1>
					<div class="het-inspector__sub">Szybki test: duplikaty, konflikty, brakujące callbacki cron, ciężkie autoload, ryzyka REST.</div>
				</div>
				<div class="het-inspector__topbar-actions">
<div class="het-inspector__topbar-actions">
    <button class="button button-secondary" id="het-preflight-snap-pre">Snapshot przed aktualizacją</button>
    <button class="button button-secondary" id="het-preflight-snap-post">Snapshot po aktualizacji + diff</button>
    <button class="button button-primary" id="het-preflight-run">Uruchom preflight</button>
</div>
</div>
			</div>
			<div class="het-card het-card--wide">
				<div class="het-card__head">
					<h2>Wynik</h2>
					<div class="het-card__meta">Odświeża się po wykonaniu</div>
				</div>
				<div class="het-card__content">
					<div id="het-preflight-out" class="het-table-wrap">—</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_snapshots_list() {
		$this->verify_nonce();
		global $wpdb;
		$type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'plugins';
		$rows = $wpdb->get_results($wpdb->prepare("SELECT id, created_at, snapshot_type, payload_hash FROM {$this->table_snapshots} WHERE snapshot_type=%s ORDER BY id DESC LIMIT 60", $type), ARRAY_A);
		wp_send_json_success(array('rows' => $rows));
	}

	public function ajax_snapshots_make() {
		$this->verify_nonce();
		// Zrób szybki zestaw snapshotów (bez ciężkich operacji)
		$this->snapshot_plugins_and_conflicts();
		$this->snapshot_hooks_and_relations();
		$this->snapshot_system_tick();
		$this->performance_tick();
		wp_send_json_success(array('ok' => 1, 'ts' => current_time('mysql')));
	}

	public function ajax_snapshots_diff() {
		$this->verify_nonce();
		$a = isset($_POST['a']) ? (int)$_POST['a'] : 0;
		$b = isset($_POST['b']) ? (int)$_POST['b'] : 0;
		if (!$a || !$b) { wp_send_json_error(array('message'=>'Brak A/B.'), 400); }

		$ra = $this->get_snapshot_row_by_id($a);
		$rb = $this->get_snapshot_row_by_id($b);
		if (!$ra || !$rb) { wp_send_json_error(array('message'=>'Nie znaleziono snapshotów.'), 404); }
		if ($ra['snapshot_type'] !== $rb['snapshot_type']) { wp_send_json_error(array('message'=>'Różne typy snapshotów.'), 400); }

		$diff = $this->diff_snapshots_payload($ra['payload'], $rb['payload']);
		wp_send_json_success(array(
			'type' => $ra['snapshot_type'],
			'a' => array('id'=>(int)$ra['id'], 'created_at'=>$ra['created_at']),
			'b' => array('id'=>(int)$rb['id'], 'created_at'=>$rb['created_at']),
			'diff' => $diff,
		));
	}

	

public function ajax_preupdate_snapshot() {
    $this->verify_nonce();
    $id = $this->make_named_snapshot('pre_update');
    update_option('het_inspector_last_pre_update_snapshot_id', (int)$id, false);
    wp_send_json_success(array('id' => (int)$id, 'created_at' => current_time('mysql')));
}

public function ajax_postupdate_diff() {
    $this->verify_nonce();
    $pre_id = (int)get_option('het_inspector_last_pre_update_snapshot_id', 0);
    if (!$pre_id) { wp_send_json_error(array('message' => 'Brak snapshotu przed aktualizacją.'), 400); }
    $post_id = $this->make_named_snapshot('post_update');
    $ra = $this->get_snapshot_row_by_id($pre_id);
    $rb = $this->get_snapshot_row_by_id($post_id);
    if (!$ra || !$rb) { wp_send_json_error(array('message' => 'Nie znaleziono snapshotów.'), 404); }
    $diff = $this->diff_snapshots_payload($ra['payload'], $rb['payload']);
    wp_send_json_success(array(
        'pre' => array('id' => (int)$pre_id, 'created_at' => $ra['created_at']),
        'post' => array('id' => (int)$post_id, 'created_at' => $rb['created_at']),
        'diff' => $diff,
    ));
}

public function ajax_safe_status() {
    $this->verify_nonce();
    $enabled = (int)get_option('het_inspector_safe_mode', 0) === 1;
    $blocked = get_option('het_inspector_blocked_plugins', array());
    if (!is_array($blocked)) { $blocked = array(); }
    wp_send_json_success(array(
        'enabled' => $enabled,
        'blocked' => array_values(array_unique(array_map('sanitize_key', $blocked))),
        'threshold' => (int)get_option('het_inspector_safe_threshold', 3),
        'window_sec' => (int)get_option('het_inspector_safe_window_sec', 600),
    ));
}

public function ajax_safe_toggle() {
    $this->verify_nonce();
    $enabled = (int)get_option('het_inspector_safe_mode', 0) === 1;
    $new = $enabled ? 0 : 1;
    update_option('het_inspector_safe_mode', $new, false);
    wp_send_json_success(array('enabled' => (bool)$new));
}

public function ajax_safe_unblock() {
    $this->verify_nonce();
    $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
    if (!$slug) { wp_send_json_error(array('message'=>'Brak slug.'), 400); }
    $blocked = get_option('het_inspector_blocked_plugins', array());
    if (!is_array($blocked)) { $blocked = array(); }
    $blocked = array_values(array_diff(array_map('sanitize_key', $blocked), array($slug)));
    update_option('het_inspector_blocked_plugins', $blocked, false);
    wp_send_json_success(array('blocked' => $blocked));
}

private function make_named_snapshot($type) {
    // lekki snapshot systemowy – wystarczający do diff "przed/po aktualizacji"
    $data = array(
        'active_plugins' => get_option('active_plugins', array()),
        'plugins' => $this->collect_plugins_snapshot(),
        'conflicts' => $this->collect_conflicts_snapshot(),
        'perf' => $this->collect_perf_snapshot(),
        'security' => $this->collect_security_snapshot(),
        'relations_summary' => $this->collect_relations_summary(),
        'generated_at' => current_time('mysql'),
    );
    return $this->insert_snapshot($type, $data);
}

private function insert_snapshot($type, $payload) {
    global $wpdb;
    $json = wp_json_encode($payload);
    $hash = md5($json);
    $wpdb->insert($this->table_snapshots, array(
        'created_at' => current_time('mysql'),
        'snapshot_type' => $type,
        'payload_hash' => $hash,
        'payload' => $json,
    ), array('%s','%s','%s','%s'));
    return (int)$wpdb->insert_id;
}


private function collect_plugins_snapshot() {
    // plugin slug => version (jeśli możliwe)
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $plugins = get_plugins();
    $out = array();
    foreach ($plugins as $file => $meta) {
        $slug = sanitize_key(strtok($file, '/'));
        if (!isset($out[$slug])) { $out[$slug] = array(); }
        $out[$slug][$file] = array(
            'name' => isset($meta['Name']) ? $meta['Name'] : '',
            'version' => isset($meta['Version']) ? $meta['Version'] : '',
        );
    }
    return $out;
}

private function collect_conflicts_snapshot() {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT created_at, issue_type, severity, plugin_slug, title FROM {$this->table_issues} WHERE issue_type=%s ORDER BY id DESC LIMIT 120", 'conflict'), ARRAY_A);
    return $rows;
}

private function collect_perf_snapshot() {
    $perf = get_option(self::OPT_LAST_PERF, array());
    if (!is_array($perf)) { $perf = array(); }
    return $perf;
}

private function collect_security_snapshot() {
    $sec = get_option(self::OPT_SECURITY_PROGRESS, array());
    if (!is_array($sec)) { $sec = array(); }
    return $sec;
}


	private function run_preflight_checks() {
		$settings = $this->get_settings();
		$results = array();

		// 1) Duplikaty wariantów
		$dups = $this->detect_duplicate_variants();
		$results[] = array(
			'check' => 'Duplikaty wariantów wtyczek',
			'status' => empty($dups) ? 'OK' : 'WARN',
			'details' => $dups,
			'recommendation' => empty($dups) ? 'Brak.' : 'Zostaw tylko jedną wersję (np. heterodyna-seo/) i usuń/wyłącz warianty upgrade/test.',
		);

		// 2) Cron: czy nie ma podejrzanie dużo eventów
		$cron = $this->collect_cron_stats();
		$warn = (int)$settings['cron_warn_events'];
		$results[] = array(
			'check' => 'WP-Cron (liczba eventów)',
			'status' => ($cron['events_total'] > $warn) ? 'WARN' : 'OK',
			'details' => $cron,
			'recommendation' => ($cron['events_total'] > $warn) ? 'Masz dużo eventów. Sprawdź w Inspektor → Wydajność oraz wyłącz niepotrzebne crony / popraw callbacki.' : 'OK.',
		);

		// 3) Autoload rozmiar
		$autoload = $this->collect_autoload_stats();
		$kb = (int)($autoload['total_bytes']/1024);
		$crit = (int)$settings['autoload_critical_kb'];
		$warnkb = (int)$settings['autoload_warn_kb'];
		$status = ($kb >= $crit) ? 'CRIT' : (($kb >= $warnkb) ? 'WARN' : 'OK');
		$results[] = array(
			'check' => 'Autoload wp_options (KB)',
			'status' => $status,
			'details' => array('kb'=>$kb,'top'=>$autoload['top']),
			'recommendation' => ($status === 'OK') ? 'OK.' : 'Zmień autoload dla dużych opcji na no, przenieś cache do transients, ogranicz rozmiar payloadów.',
		);

		// 4) Ostatnie fatale
		global $wpdb;
		$fatal = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table_events} WHERE level='fatal' AND created_at >= (NOW() - INTERVAL 6 HOUR)");
		$results[] = array(
			'check' => 'Fatale (6h)',
			'status' => ($fatal > 0) ? 'WARN' : 'OK',
			'details' => array('fatals'=>$fatal),
			'recommendation' => ($fatal > 0) ? 'Masz świeże fatale. Zanim wgrasz aktualizację, sprawdź Inspektor → Incydenty i napraw źródło.' : 'OK.',
		);

		return array('results' => $results, 'generated_at' => current_time('mysql'));
	}


    private function verify_nonce() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień.'), 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'het_inspector_nonce')) {
            wp_send_json_error(array('message' => 'Błędny nonce.'), 403);
        }
    }

    public function ajax_stats() {
        $this->verify_nonce();
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $fatal = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_events} WHERE level='fatal' AND created_at >= %s", $since));
        $err = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_events} WHERE level IN ('error','performance') AND created_at >= %s", $since));
        $warn = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_events} WHERE level='warning' AND created_at >= %s", $since));

        $conflicts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table_issues} WHERE issue_type='conflict'");

        wp_send_json_success(array(
            'fatal' => $fatal,
            'error' => $err,
            'warning' => $warn,
            'conflicts' => $conflicts,
            'last_run' => (string)get_option(self::OPT_LAST_RUN, ''),
        ));
    }

    public function ajax_incidents() {
        $this->verify_nonce();
        global $wpdb;

        $rows = $wpdb->get_results("SELECT id, created_at, level, source_type, source_slug, message, file_path, line_no FROM {$this->table_events} ORDER BY id DESC LIMIT 25", ARRAY_A);
        wp_send_json_success(array('rows' => $rows));
    }

    public function ajax_conflicts() {
        $this->verify_nonce();
        global $wpdb;

        $rows = $wpdb->get_results("SELECT id, created_at, severity, plugin_slug, title, recommendation FROM {$this->table_issues} WHERE issue_type='conflict' ORDER BY id DESC LIMIT 25", ARRAY_A);
        wp_send_json_success(array('rows' => $rows));
    }

	public function ajax_graph() {
		$this->verify_nonce();
		global $wpdb;

		$days = isset($_POST['days']) ? (int)$_POST['days'] : 7;
		$days = max(1, min(30, $days));
		$since = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

		// Relacje: plugin -> target (table|endpoint|hook)
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT relation_type, plugin_slug, target FROM {$this->table_relations} WHERE created_at >= %s ORDER BY id DESC LIMIT 5000",
				$since
			),
			ARRAY_A
		);

		$nodes = array();
		$edges = array();
		$seen_nodes = array();

		$add_node = function($id, $label, $type) use (&$nodes, &$seen_nodes) {
			if (isset($seen_nodes[$id])) { return; }
			$seen_nodes[$id] = true;
			$nodes[] = array('id' => $id, 'label' => $label, 'type' => $type);
		};

		foreach ($rows as $r) {
			$rt = (string)$r['relation_type'];
			$plugin = (string)$r['plugin_slug'];
			$target = (string)$r['target'];
			if ($plugin === '' || $target === '') { continue; }

			$p_id = 'p:' . $plugin;
			$t_id = $rt . ':' . $target;
			$add_node($p_id, $plugin, 'plugin');
			$add_node($t_id, $target, $rt);

			$edges[] = array(
				'source' => $p_id,
				'target' => $t_id,
				'type' => $rt,
			);
		}

		wp_send_json_success(array(
			'nodes' => $nodes,
			'edges' => $edges,
			'since' => $since,
			'days' => $days,
		));
	}

	public function capture_rest_routes() {
		// Minimalny collector: bierzemy routy już zarejestrowane i przypisujemy je po callbacku do wtyczki.
		// Nie robimy INSERTów tu — korzystamy z bufora relacji.
		try {
			$server = rest_get_server();
			$routes = $server ? $server->get_routes() : array();
			if (!is_array($routes) || empty($routes)) { return; }

			$limit = 400; // zabezpieczenie
			$added = 0;
			foreach ($routes as $route => $handlers) {
				if ($added >= $limit) { break; }
				if (!is_array($handlers)) { continue; }
				foreach ($handlers as $h) {
					if (!is_array($h)) { continue; }
					$cb = isset($h['callback']) ? $h['callback'] : null;
					$slug = $this->plugin_slug_from_callback($cb);
					if (!$slug) { continue; }
					$this->buffer_relation('endpoint', $slug, $route, array(
						'methods' => isset($h['methods']) ? $h['methods'] : null,
					));
					$added++;
				}
			}
		} catch (Throwable $e) {
			// Nie logujemy w kółko — tylko minimalnie
		}
	}

	public function render_explorer_page() {
		if (!current_user_can('manage_options')) {
			wp_die('Brak uprawnień.');
		}
		?>
		<div class="wrap het-inspector het-inspector--explorer">
			<div class="het-inspector__topbar">
				<div>
					<h1>Eksplorator zależności</h1>
					<div class="het-inspector__sub">2D (Cytoscape) + opcjonalny tryb 3D • Odświeżanie danych: co minutę</div>
				</div>
				<div class="het-inspector__topbar-actions">
					<a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=het-inspektor-explorer&fullscreen=1')); ?>" target="_blank">Pełny ekran</a>
				</div>
			</div>

			<div class="het-explorer" data-page="explorer">
				<div class="het-explorer__sidebar">
					<div class="het-explorer__card">
						<label><strong>Zakres danych</strong></label>
						<select id="het-x-days" class="widefat">
							<option value="1">1 dzień</option>
							<option value="7" selected>7 dni</option>
							<option value="14">14 dni</option>
							<option value="30">30 dni</option>
						</select>
						<p class="description">Im dłuższy zakres, tym większy graf. Dla dev zwykle wystarczy 7 dni.</p>
					</div>

					<div class="het-explorer__card">
						<label><strong>Widok</strong></label>
						<div class="het-explorer__toggles">
							<button class="button" id="het-x-mode-2d" type="button">2D</button>
							<button class="button" id="het-x-mode-3d" type="button">3D</button>
						</div>
						<p class="description">3D do „przelotu” po strukturze. 2D jest czytelniejsze i lżejsze.</p>
					</div>

					<div class="het-explorer__card">
						<label><strong>Motyw grafu</strong></label>
						<select id="het-x-theme" class="widefat">
							<option value="auto" selected>Auto</option>
							<option value="light">Jasny (czarne napisy)</option>
							<option value="dark">Ciemny (białe napisy)</option>
							<option value="contrast">Kontrastowy</option>
						</select>
						<p class="description">Auto dopasowuje tło do preferencji przeglądarki. Możesz wymusić jasny lub ciemny.</p>
					</div>

					<div class="het-explorer__card">
						<label><strong>Filtr</strong></label>
						<input id="het-x-filter" class="widefat" type="text" placeholder="np. heterodyna-seo, wp_options, /wp-json/..." />
						<p class="description">Filtruje węzły po etykiecie (plugin/tabela/endpoint/hook).</p>
					</div>

					<div class="het-explorer__card">
						<button class="button button-primary" id="het-x-refresh" type="button">Odśwież dane</button>
						<div class="het-explorer__status" id="het-x-status">Gotowe.</div>
					</div>
				</div>

				<div class="het-explorer__canvas">
					<div id="het-graph-2d" class="het-graph"></div>
					<div id="het-graph-3d" class="het-graph" style="display:none"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/* =========================
	 *  Bezpieczeństwo (Pack 3)
	 * ========================= */

	private function security_default_state() {
		return array(
			'active' => 0,
			'started_at' => '',
			'finished_at' => '',
			'plugins' => array(),
			'p_idx' => 0,
			'file_idx' => 0,
			'files' => array(),
			'current_slug' => '',
			'deleted_old' => 0,
			'processed_files' => 0,
			'total_plugins' => 0,
			'last_msg' => '',
		);
	}

	private function security_get_state() {
		$s = get_option(self::OPT_SECURITY_STATE, null);
		if (!is_array($s)) {
			$s = $this->security_default_state();
		}
		return $s;
	}

	private function security_set_state($s) {
		if (!is_array($s)) { $s = $this->security_default_state(); }
		update_option(self::OPT_SECURITY_STATE, $s, false);
	}

	public function render_security_page() {
		if (!current_user_can('manage_options')) {
			wp_die('Brak uprawnień.');
		}
		?>
		<div class="wrap het-inspector het-inspector--security">
			<div class="het-inspector__topbar">
				<div>
					<h1>Bezpieczeństwo</h1>
					<div class="het-inspector__sub">Skan heurystyczny kodu + szybkie ryzyka (REST/SQL/nonce/sanitizacja). Odświeżanie co minutę.</div>
				</div>
				<div class="het-inspector__topbar-actions">
					<button class="button button-primary" id="het-sec-start" type="button">Uruchom pełny skan</button>
					<a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=het-inspektor-security&fullscreen=1')); ?>" target="_blank">Pełny ekran</a>
				</div>
			</div>

			<div class="het-grid">
				<div class="het-card">
					<div class="het-card__head">
						<h2>Podsumowanie ryzyk (24h)</h2>
						<div class="het-card__meta" id="het-sec-meta">Ładowanie…</div>
					</div>
					<div class="het-card__content">
						<div class="het-metrics">
							<div class="het-metric"><div class="het-metric__label">Critical</div><div class="het-metric__value" id="sec-critical">—</div></div>
							<div class="het-metric"><div class="het-metric__label">High</div><div class="het-metric__value" id="sec-high">—</div></div>
							<div class="het-metric"><div class="het-metric__label">Medium</div><div class="het-metric__value" id="sec-medium">—</div></div>
							<div class="het-metric"><div class="het-metric__label">Low</div><div class="het-metric__value" id="sec-low">—</div></div>
						</div>
						<div class="het-sec-progress" id="het-sec-progress">—</div>
						<p class="description">To nie jest pełny SAST jak w enterprise — to szybki, praktyczny radar pod WordPress: permission_callback, SQL prepare, nonce, sanitizacja, podejrzane funkcje (eval/base64/upload).</p>
					</div>
				</div>

				<div class="het-card het-card--wide">
					<div class="het-card__head">
						<h2>Wykryte problemy (top 200)</h2>
						<div class="het-card__meta">Sort: severity → najnowsze</div>
					</div>
					<div class="het-card__content">
						<div id="het-sec-issues" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_performance_page() {
		if (!current_user_can('manage_options')) {
			wp_die('Brak uprawnień.');
		}
		?>
		<div class="wrap het-inspector het-inspector--fullscreen">
			<div class="het-inspector__topbar">
				<div>
					<h1>Wydajność i Google</h1>
					<div class="het-inspector__sub">
						Wersja: <strong><?php echo esc_html(HET_INSPECTOR_VERSION); ?></strong>
						&nbsp;•&nbsp; Ostatni audyt wydajności: <strong id="het-perf-last">—</strong>
						&nbsp;•&nbsp; Odświeżanie: <strong><?php echo (int)$this->get_settings()['refresh_seconds']; ?>s</strong>
					</div>
				</div>
				<div class="het-inspector__topbar-actions">
					<a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=het-inspektor-performance&fullscreen=1')); ?>" target="_blank">Pełny ekran</a>
				</div>
			</div>

			<div class="het-grid">
				<div class="het-card">
					<div class="het-card__head">
						<h2>Podsumowanie (24h)</h2>
						<div class="het-card__meta" id="het-perf-meta">Ładowanie…</div>
					</div>
					<div class="het-card__content">
						<div class="het-metrics">
							<div class="het-metric"><div class="het-metric__label">Slow queries (24h)</div><div class="het-metric__value" id="perf-slow">—</div></div>
							<div class="het-metric"><div class="het-metric__label">Autoload (KB)</div><div class="het-metric__value" id="perf-autoload">—</div></div>
							<div class="het-metric"><div class="het-metric__label">WP-Cron hooki</div><div class="het-metric__value" id="perf-cron-hooks">—</div></div>
							<div class="het-metric"><div class="het-metric__label">WP-Cron eventy</div><div class="het-metric__value" id="perf-cron-events">—</div></div>
						</div>
						<p class="description">
							Slow queries: Inspektor automatycznie włącza zbieranie zapytań (SAVEQUERIES-like). Dla 100% pokrycia od samego startu WordPress najlepiej też ustawić <code>define('SAVEQUERIES', true);</code> w <code>wp-config.php</code>.
							Autoload pokazuje realny koszt ładowania opcji przy każdym request.
						</p>
					</div>
				</div>

				<div class="het-card het-card--wide">
					<div class="het-card__head">
						<h2>TOP autoload options</h2>
						<div class="het-card__meta">Najcięższe wpisy w wp_options z autoload=yes</div>
					</div>
					<div class="het-card__content">
						<div id="het-perf-autoload-table" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>

				<div class="het-card het-card--wide">
					<div class="het-card__head">
						<h2>WP-Cron (statystyki)</h2>
						<div class="het-card__meta">Ile hooków i eventów siedzi w harmonogramie</div>
					</div>
					<div class="het-card__content">
						<div id="het-perf-cron" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>

				<div class="het-card het-card--wide">
					<div class="het-card__head">
						<h2>Slow queries (ostatnie 50)</h2>
						<div class="het-card__meta">Źródło: SAVEQUERIES + próg z ustawień</div>
					</div>
					<div class="het-card__content">
						<div id="het-perf-slow-table" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_ai_page() {
		if (!current_user_can('manage_options')) {
			wp_die('Brak uprawnień.');
		}
		?>
		<div class="wrap het-inspector het-inspector--fullscreen">
			<div class="het-inspector__topbar">
				<div>
					<h1>AI i rekomendacje</h1>
					<div class="het-inspector__sub">
						Odświeżanie: <strong><?php echo (int)$this->get_settings()['refresh_seconds']; ?>s</strong>
						&nbsp;•&nbsp; Dane są generowane z zebranych incydentów, konfliktów, bezpieczeństwa i wydajności.
					</div>
				</div>
				<div class="het-inspector__topbar-actions">
					<a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=het-inspektor-ai&fullscreen=1')); ?>" target="_blank">Pełny ekran</a>
				</div>
			</div>

			<div class="het-grid">
				<div class="het-card">
					<div class="het-card__head">
						<h2>Ustawienia AI</h2>
						<div class="het-card__meta">Opcjonalne: zewnętrzny endpoint LLM lub tryb lokalny (heurystyki)</div>
					</div>
					<div class="het-card__content">
						<div id="het-ai-settings" class="het-form">Ładowanie…</div>
						<p class="description">W trybie <strong>HTTP</strong> Inspektor wyśle JSON z kontekstem do Twojego endpointu i oczekuje tekstu (plain) lub JSON {"text":"..."}. Klucz jest trzymany w <code>wp_options</code> z <code>autoload=no</code>.</p>
					</div>
				</div>

				<div class="het-card">
					<div class="het-card__head">
						<h2>Generowanie rekomendacji</h2>
						<div class="het-card__meta" id="het-ai-status">—</div>
					</div>
					<div class="het-card__content">
						<div class="het-ai-actions">
							<select id="het-ai-scope">
								<option value="system">System (cały serwer)</option>
								<option value="plugin">Tylko jedna wtyczka</option>
							</select>
							<input type="text" id="het-ai-plugin" placeholder="plugin_slug (np. heterodyna-seo)" style="min-width:260px" />
							<button class="button button-primary" id="het-ai-generate">Generuj</button>
						</div>
						<div id="het-ai-result" class="het-ai-result"><em>Wynik pojawi się tutaj.</em></div>
					</div>
				</div>

				<div class="het-card">
					<div class="het-card__head">
						<h2>Historia raportów</h2>
						<div class="het-card__meta">Ostatnie 50</div>
					</div>
					<div class="het-card__content">
						<div id="het-ai-history" class="het-table-wrap">Ładowanie…</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_security_start() {
		$this->verify_nonce();
		$this->security_start_scan(true);
		wp_send_json_success(array('message' => 'Skan bezpieczeństwa uruchomiony.'));
	}

	public function ajax_security_progress() {
		$this->verify_nonce();
		$s = $this->security_get_state();
		wp_send_json_success(array('state' => $s));
	}

	public function ajax_security_stats() {
		$this->verify_nonce();
		global $wpdb;
		$since = gmdate('Y-m-d H:i:s', time() - 86400);
		$crit = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_issues} WHERE issue_type='security' AND severity='critical' AND created_at >= %s", $since));
		$high = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_issues} WHERE issue_type='security' AND severity='high' AND created_at >= %s", $since));
		$med  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_issues} WHERE issue_type='security' AND severity='medium' AND created_at >= %s", $since));
		$low  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_issues} WHERE issue_type='security' AND severity='low' AND created_at >= %s", $since));
		$s = $this->security_get_state();
		wp_send_json_success(array('critical'=>$crit,'high'=>$high,'medium'=>$med,'low'=>$low,'state'=>$s));
	}

	public function ajax_security_issues() {
		$this->verify_nonce();
		global $wpdb;
		$rows = $wpdb->get_results("SELECT id, created_at, severity, plugin_slug, title, details, recommendation FROM {$this->table_issues} WHERE issue_type='security' ORDER BY FIELD(severity,'critical','high','medium','low'), id DESC LIMIT 200", ARRAY_A);
		wp_send_json_success(array('rows'=>$rows));
	}

	// -----------------------------
	// Wydajność (Pack 4)
	// -----------------------------
	public function ajax_perf_stats() {
		$this->verify_nonce();
		global $wpdb;
		$since = gmdate('Y-m-d H:i:s', time() - 86400);
		$slow = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_events} WHERE level='performance' AND source_type='slow_query' AND created_at >= %s", $since));

		$perf = $this->get_last_perf_snapshot();
		$cron = isset($perf['cron']) && is_array($perf['cron']) ? $perf['cron'] : array();
		$autoload = isset($perf['autoload']) && is_array($perf['autoload']) ? $perf['autoload'] : array();
		wp_send_json_success(array(
			'last_perf' => get_option(self::OPT_PERF_LAST, ''),
			'slow_24h' => $slow,
			'autoload_total_kb' => isset($autoload['total_kb']) ? (int)$autoload['total_kb'] : 0,
			'cron_hooks' => isset($cron['hooks']) ? (int)$cron['hooks'] : 0,
			'cron_events' => isset($cron['events']) ? (int)$cron['events'] : 0,
			'savequeries' => (defined('SAVEQUERIES') && SAVEQUERIES) ? 1 : 0,
		));
	}

	public function ajax_perf_details() {
		$this->verify_nonce();
		$perf = $this->get_last_perf_snapshot();
		global $wpdb;
		$rows = $wpdb->get_results("SELECT created_at, source_slug, message, context FROM {$this->table_events} WHERE level='performance' AND source_type='slow_query' ORDER BY id DESC LIMIT 50", ARRAY_A);
		wp_send_json_success(array(
			'perf' => $perf,
			'slow_rows' => $rows,
		));
	}

	// ---------------------------
	// AI & Rekomendacje (Pack 5)
	// ---------------------------
	private function ai_get_last_run_ts() {
		$ts = get_option('het_inspector_ai_last_ts', 0);
		return (int)$ts;
	}

	private function ai_set_last_run_ts() {
		update_option('het_inspector_ai_last_ts', time(), false);
	}

	public function ai_tick() {
		$cfg = $this->get_ai_settings();
		if (empty($cfg['enabled'])) { return; }
		$interval = max(5, (int)$cfg['run_interval_min']);
		$last = $this->ai_get_last_run_ts();
		if ($last && (time() - $last) < ($interval * 60)) { return; }
		// W tle generujemy rekomendacje systemowe (bez narzucania zmian)
		$this->ai_generate_report('system', '', 'auto');
		$this->ai_set_last_run_ts();
	}

	public function ajax_ai_settings_get() {
		$this->verify_nonce();
		$cfg = $this->get_ai_settings();
		// nigdy nie zwracamy klucza wprost do JS — UI pokaże maskę
		$masked = $cfg;
		if (!empty($masked['api_key'])) {
			$masked['api_key'] = str_repeat('•', 8) . ' (ustawione)';
		}
		wp_send_json_success(array('settings' => $masked));
	}

	public function ajax_ai_settings_save() {
		$this->verify_nonce();
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Brak uprawnień.'));
		}
		$enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 0;
		$provider = isset($_POST['provider']) ? sanitize_text_field((string)$_POST['provider']) : 'local';
		$endpoint = isset($_POST['endpoint_url']) ? esc_url_raw((string)$_POST['endpoint_url']) : '';
		$model = isset($_POST['model']) ? sanitize_text_field((string)$_POST['model']) : '';
		$interval = isset($_POST['run_interval_min']) ? (int)$_POST['run_interval_min'] : 30;
		$api_key = null;
		if (isset($_POST['api_key']) && is_string($_POST['api_key'])) {
			$k = trim((string)$_POST['api_key']);
			// jeśli user wkleił maskę, nie nadpisujemy
			if ($k !== '' && strpos($k, '•') === false) { $api_key = $k; }
		}
		$new = array(
			'enabled' => (bool)$enabled,
			'provider' => in_array($provider, array('local','http'), true) ? $provider : 'local',
			'endpoint_url' => $endpoint,
			'model' => $model,
			'run_interval_min' => max(5, min(240, $interval)),
		);
		if ($api_key !== null) { $new['api_key'] = $api_key; }
		$saved = $this->update_ai_settings($new);
		wp_send_json_success(array('saved' => true, 'settings' => array_merge($saved, array('api_key' => !empty($saved['api_key']) ? '•••••••• (ustawione)' : ''))));
	}

	public function ajax_ai_generate() {
		$this->verify_nonce();
		$scope = isset($_POST['scope']) ? sanitize_text_field((string)$_POST['scope']) : 'system';
		$plugin = isset($_POST['plugin_slug']) ? sanitize_key((string)$_POST['plugin_slug']) : '';
		$mode = isset($_POST['mode']) ? sanitize_text_field((string)$_POST['mode']) : 'manual';
		$report = $this->ai_generate_report($scope, $plugin, $mode);
		wp_send_json_success(array('report' => $report));
	}

	public function ajax_ai_history() {
		$this->verify_nonce();
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, created_at, scope, plugin_slug, provider, model FROM {$this->table_ai_reports} ORDER BY id DESC LIMIT %d",
			50
		), ARRAY_A);
		wp_send_json_success(array('rows' => $rows));
	}

	private function ai_generate_report($scope, $plugin_slug, $mode) {
		$scope = in_array($scope, array('system','plugin','custom'), true) ? $scope : 'system';
		$plugin_slug = $plugin_slug ? sanitize_key($plugin_slug) : '';
		$cfg = $this->get_ai_settings();
		$payload = $this->ai_build_context_payload($scope, $plugin_slug);
		$prompt = $this->ai_build_prompt($payload);
		$prompt_hash = hash('sha256', $prompt);

		$result_text = '';
		$provider = (string)$cfg['provider'];
		$model = (string)$cfg['model'];
		$meta = array('mode' => $mode, 'provider' => $provider, 'model' => $model, 'ts' => time());

		if ($provider === 'http' && !empty($cfg['endpoint_url'])) {
			$result_text = $this->ai_call_http_provider($cfg, $prompt, $payload);
			if (!$result_text) {
				$result_text = $this->ai_local_recommendations($payload);
				$meta['fallback'] = 'local';
			}
		} else {
			$result_text = $this->ai_local_recommendations($payload);
		}

		// zapis
		global $wpdb;
		$this->capture_disabled = true;
		$wpdb->insert($this->table_ai_reports, array(
			'created_at' => current_time('mysql'),
			'scope' => $scope,
			'plugin_slug' => $plugin_slug,
			'provider' => $provider ? $provider : 'local',
			'model' => $model,
			'prompt_hash' => $prompt_hash,
			'result' => (string)$result_text,
			'meta' => wp_json_encode($meta),
		));
		$this->capture_disabled = false;

		return array(
			'created_at' => current_time('mysql'),
			'scope' => $scope,
			'plugin_slug' => $plugin_slug,
			'provider' => $provider,
			'model' => $model,
			'prompt_hash' => $prompt_hash,
			'result' => $result_text,
		);
	}

	private function ai_call_http_provider($cfg, $prompt, $payload) {
		$url = (string)$cfg['endpoint_url'];
		$api_key = (string)$cfg['api_key'];
		$body = array(
			'prompt' => $prompt,
			'context' => $payload,
			'model' => (string)$cfg['model'],
		);
		$headers = array('Content-Type' => 'application/json');
		if ($api_key) { $headers['Authorization'] = 'Bearer ' . $api_key; }
		$r = wp_remote_post($url, array(
			'timeout' => 25,
			'headers' => $headers,
			'body' => wp_json_encode($body),
		));
		if (is_wp_error($r)) { return ''; }
		$code = (int)wp_remote_retrieve_response_code($r);
		$txt = (string)wp_remote_retrieve_body($r);
		if ($code < 200 || $code >= 300) { return ''; }
		// akceptujemy: {"text":"..."} lub czysty tekst
		$j = json_decode($txt, true);
		if (is_array($j) && isset($j['text'])) {
			return (string)$j['text'];
		}
		return $txt;
	}

	private function ai_build_context_payload($scope, $plugin_slug) {
		global $wpdb;
		$since = gmdate('Y-m-d H:i:s', time() - 24*3600);
		// Zbieramy: top issues (security/perf/conflict), top fatale, top relacje
		$where_plugin = '';
		$params = array();
		if ($scope === 'plugin' && $plugin_slug) {
			$where_plugin = " AND plugin_slug = %s";
			$params[] = $plugin_slug;
		}
		$issues_sql = "SELECT issue_type, severity, plugin_slug, title, recommendation FROM {$this->table_issues} WHERE created_at >= %s{$where_plugin} ORDER BY FIELD(severity,'critical','high','medium','low'), id DESC LIMIT 100";
		$issues_params = array_merge(array($since), $params);
		$issues = $wpdb->get_results($wpdb->prepare($issues_sql, $issues_params), ARRAY_A);

		$events_sql = "SELECT level, source_slug, message, file_path, line_no FROM {$this->table_events} WHERE created_at >= %s AND (level='fatal' OR level='error') ORDER BY id DESC LIMIT 50";
		$events = $wpdb->get_results($wpdb->prepare($events_sql, array($since)), ARRAY_A);

		$rel_sql = "SELECT relation_type, plugin_slug, target FROM {$this->table_relations} " . ($scope === 'plugin' && $plugin_slug ? "WHERE plugin_slug=%s" : "") . " ORDER BY id DESC LIMIT 200";
		$rels = ($scope === 'plugin' && $plugin_slug) ? $wpdb->get_results($wpdb->prepare($rel_sql, array($plugin_slug)), ARRAY_A) : $wpdb->get_results($rel_sql, ARRAY_A);

		$plugins = array();
		$all = get_plugins();
		foreach ($all as $file => $data) {
			$slug = dirname($file);
			if ($slug === '.' || $slug === '') { $slug = basename($file, '.php'); }
			$slug = sanitize_key($slug);
			if ($slug === '' ) { continue; }
			$plugins[] = array('slug' => $slug, 'name' => isset($data['Name']) ? $data['Name'] : '', 'version' => isset($data['Version']) ? $data['Version'] : '');
		}

		return array(
			'scope' => $scope,
			'plugin_slug' => $plugin_slug,
			'generated_at' => current_time('mysql'),
			'issues' => $issues,
			'events' => $events,
			'relations' => $rels,
			'plugins' => $plugins,
		);
	}

	private function ai_build_prompt($payload) {
		$hint = "Jesteś doradcą technicznym. Na podstawie danych z audytu WordPress (wtyczki, relacje, incydenty, problemy bezpieczeństwa i wydajności) wypisz rekomendacje. Skup się na: (1) potencjalnych konfliktach i duplikatach, (2) stabilności (fatale/cron), (3) bezpieczeństwie (nonce/permission_callback/prepare), (4) wydajności (autoload, slow queries). Podawaj konkretne kroki oraz ryzyko. Zwróć wynik w języku polskim, w punktach, z sekcjami.\n";
		return $hint . "\nDANE (JSON):\n" . wp_json_encode($payload);
	}

	private function ai_local_recommendations($payload) {
		// Heurystyczne „AI bez AI” – deterministyczne rekomendacje na podstawie issues
		$issues = isset($payload['issues']) && is_array($payload['issues']) ? $payload['issues'] : array();
		$by_type = array();
		foreach ($issues as $i) {
			$t = isset($i['issue_type']) ? $i['issue_type'] : 'other';
			$by_type[$t][] = $i;
		}
		$out = array();
		$out[] = "## Podsumowanie";
		$out[] = "Zakres: " . (isset($payload['scope']) ? $payload['scope'] : 'system') . (isset($payload['plugin_slug']) && $payload['plugin_slug'] ? " (".$payload['plugin_slug'].")" : "");
		$out[] = "";
		if (!empty($by_type['conflict'])) {
			$out[] = "## Konflikty / duplikaty";
			$out[] = "Priorytet: usuń duplikaty wariantów tej samej wtyczki (np. foldery z dopiskami upgrade).";
			$out[] = "Kroki:";
			$out[] = "- zostaw jedną wersję folderu;";
			$out[] = "- upewnij się, że w bazie aktywna jest tylko jedna instancja;";
			$out[] = "- wprowadź osłony class_exists/function_exists w bibliotekach współdzielonych.";
			$out[] = "";
		}
		if (!empty($by_type['security'])) {
			$out[] = "## Bezpieczeństwo";
			$out[] = "Priorytet: REST bez permission_callback, AJAX bez nonce, SQL bez prepare.";
			$out[] = "Kroki:";
			$out[] = "- dodaj permission_callback do register_rest_route;";
			$out[] = "- dodaj check_ajax_referer + current_user_can;";
			$out[] = "- wszystkie zapytania z danymi od użytkownika przepuść przez \$wpdb->prepare.";
			$out[] = "";
		}
		if (!empty($by_type['performance'])) {
			$out[] = "## Wydajność";
			$out[] = "Priorytet: autoload w wp_options, częste cron ticki, brak cache/transients.";
			$out[] = "Kroki:";
			$out[] = "- przenieś duże opcje na autoload=no;";
			$out[] = "- agreguj metryki zamiast logować wszystko;";
			$out[] = "- dodaj cache (transients/objects) dla ciężkich zapytań.";
			$out[] = "";
		}
		// Stabilność
		$events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : array();
		if (!empty($events)) {
			$out[] = "## Stabilność (fatale/błędy)";
			$out[] = "Wykryto błędy w ostatnich 24h. Sprawdź top wpisy w 'Incydenty' i powiązane wtyczki.";
			$out[] = "Kroki:";
			$out[] = "- wyłącz duplikaty;";
			$out[] = "- sprawdź brakujące metody callbacków cron;";
			$out[] = "- dodaj guardy: function_exists/method_exists przed wywołaniami w cron.";
			$out[] = "";
		}
		$out[] = "## Propozycja architektury";
		$out[] = "- Rozważ 'core shared' (1 wtyczka biblioteka) dla wspólnych helperów i modeli.";
		$out[] = "- Każdy moduł ma własny prefix tabel/optionów (np. het_seo_, het_ohm_...) i migracje dbDelta.";
		$out[] = "- Przed aktualizacją: snapshot + preflight (Inspektor może to wymuszać).";
		return implode("\n", $out);
	}

	private function security_start_scan($force=false) {
		$settings = $this->get_settings();
		if (empty($settings['security_scan_enabled']) && !$force) {
			return;
		}
		$s = $this->security_get_state();
		if (!empty($s['active']) && !$force) {
			return;
		}
		$plugins = array();
		$all = get_plugins();
		foreach ($all as $file => $data) {
			$slug = dirname($file);
			if ($slug === '.' || $slug === '') { $slug = basename($file, '.php'); }
			$slug = sanitize_key($slug);
			if ($slug === '' || $slug === 'heterodyna-inspektor') { continue; }
			$plugins[$slug] = true;
		}
		$plist = array_keys($plugins);
		sort($plist);
		$s = $this->security_default_state();
		$s['active'] = 1;
		$s['started_at'] = current_time('mysql');
		$s['plugins'] = $plist;
		$s['total_plugins'] = count($plist);
		$s['last_msg'] = 'Start skanu: ' . count($plist) . ' wtyczek.';
		$this->security_set_state($s);
	}

	private function security_should_auto_start() {
		$settings = $this->get_settings();
		if (empty($settings['security_scan_enabled'])) { return false; }
		$s = $this->security_get_state();
		if (!empty($s['active'])) { return false; }
		$interval = max(5, (int)$settings['security_scan_interval_min']);
		$last = isset($s['finished_at']) ? (string)$s['finished_at'] : '';
		if (!$last) { return true; }
		$ts = strtotime($last);
		if (!$ts) { return true; }
		return (time() - $ts) >= ($interval * 60);
	}

	public function security_scan_tick() {
		// auto-start po interwale
		if ($this->security_should_auto_start()) {
			$this->security_start_scan(false);
		}
		$s = $this->security_get_state();
		if (empty($s['active'])) { return; }

		// Jednorazowe czyszczenie starych wyników
		if (empty($s['deleted_old'])) {
			global $wpdb;
			$wpdb->query("DELETE FROM {$this->table_issues} WHERE issue_type='security'");
			$s['deleted_old'] = 1;
			$s['last_msg'] = 'Wyczyszczono poprzednie wyniki.';
		}

		$settings = $this->get_settings();
		$batch = max(5, min(80, (int)$settings['security_batch_files']));

		// Jeśli skończyły się wtyczki
		if ($s['p_idx'] >= (int)$s['total_plugins']) {
			$s['active'] = 0;
			$s['finished_at'] = current_time('mysql');
			$s['last_msg'] = 'Skan ukończony.';
			$this->security_set_state($s);
			return;
		}

		$slug = $s['plugins'][$s['p_idx']] ?? '';
		if (!$slug) {
			$s['p_idx']++;
			$s['file_idx'] = 0;
			$s['files'] = array();
			$s['current_slug'] = '';
			$this->security_set_state($s);
			return;
		}

		// Przy wejściu w nową wtyczkę – zbuduj listę plików
		if ($s['current_slug'] !== $slug || empty($s['files'])) {
			$s['current_slug'] = $slug;
			$s['file_idx'] = 0;
			$s['files'] = $this->security_list_php_files_for_plugin($slug, 1800);
			$s['last_msg'] = 'Skan: ' . $slug . ' (' . count($s['files']) . ' plików).';
		}

		$files = is_array($s['files']) ? $s['files'] : array();
		$start = (int)$s['file_idx'];
		$end = min(count($files), $start + $batch);
		for ($i = $start; $i < $end; $i++) {
			$path = $files[$i];
			$this->security_scan_file($slug, $path);
			$s['processed_files'] = (int)$s['processed_files'] + 1;
		}
		$s['file_idx'] = $end;

		// Koniec plików wtyczki → następna
		if ($s['file_idx'] >= count($files)) {
			$s['p_idx']++;
			$s['file_idx'] = 0;
			$s['files'] = array();
			$s['current_slug'] = '';
			$s['last_msg'] = 'Zakończono: ' . $slug;
		}

		$this->security_set_state($s);
	}

	private function security_list_php_files_for_plugin($slug, $limit = 1500) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		$files = array();
		if (!is_dir($dir)) { return $files; }
		try {
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (count($files) >= $limit) { break; }
				$path = (string)$f->getPathname();
				$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
				if ($ext !== 'php') { continue; }
				$files[] = $path;
			}
		} catch (Throwable $e) {
			return $files;
		}
		return $files;
	}

	private function security_scan_file($plugin_slug, $path) {
		if (!is_readable($path)) { return; }
		// limit rozmiaru pliku (ochrona)
		$size = @filesize($path);
		if ($size && $size > 1024 * 1024 * 2) { // 2MB
			$this->security_issue($plugin_slug, 'low', 'Duży plik PHP (>2MB)', array('file'=>$path), 'Rozważ podział pliku na mniejsze klasy/moduły.');
			return;
		}
		$content = @file_get_contents($path);
		if (!is_string($content) || $content === '') { return; }

		$lines = preg_split('/\R/', $content);
		if (!is_array($lines)) { $lines = array($content); }

		// 1) Specjalna heurystyka: register_rest_route bez permission_callback w pobliżu
		foreach ($lines as $idx => $line) {
			if (stripos($line, 'register_rest_route') === false) { continue; }
			$has_perm = false;
			for ($j = $idx; $j < min($idx + 12, count($lines)); $j++) {
				if (stripos($lines[$j], 'permission_callback') !== false) { $has_perm = true; break; }
			}
			if (!$has_perm) {
				$this->security_issue($plugin_slug, 'high', 'REST: register_rest_route bez permission_callback (heurystyka)', array(
					'file' => $path,
					'line' => $idx + 1,
					'rule' => 'rest_no_permission_callback',
					'snippet' => trim(substr($line, 0, 240)),
				), 'Dodaj permission_callback (capability check) do każdej trasy REST.');
			}
		}

		// 2) Reguły liniowe (lekko) z limitem trafień
		$rules = $this->security_rules();
		$hits = array();
		foreach ($rules as $rule) {
			$pattern = $rule['pattern'];
			$rid = $rule['id'];
			$hits[$rid] = 0;
			foreach ($lines as $idx => $line) {
				if ($hits[$rid] >= 6) { break; }
				if (!preg_match($pattern, $line)) { continue; }
				// SQL: jeśli w tej samej linii jest prepare – pomiń
				if ($rid === 'sql_no_prepare' && stripos($line, 'prepare') !== false) { continue; }
				$this->security_issue($plugin_slug, $rule['severity'], $rule['title'], array(
					'file' => $path,
					'line' => $idx + 1,
					'rule' => $rid,
					'snippet' => trim(substr($line, 0, 240)),
				), $rule['recommendation']);
				$hits[$rid]++;
			}
		}
	}

	private function security_rules() {
		return array(
			array(
				'id' => 'sql_no_prepare',
				'severity' => 'high',
				'title' => 'SQL: użycie $wpdb->query/get_results bez prepare (heurystyka)',
				'pattern' => '/\$wpdb->(query|get_results|get_row|get_var)\s*\(\s*\$[a-zA-Z0-9_]+\s*\)/',
				'recommendation' => 'Gdy w SQL są zmienne z zewnątrz, używaj $wpdb->prepare(...).',
			),
			array(
				'id' => 'direct_request_vars',
				'severity' => 'medium',
				'title' => 'Wejście: użycie $_GET/$_POST/$_REQUEST',
				'pattern' => '/\$_(GET|POST|REQUEST)\b/',
				'recommendation' => 'Waliduj i sanitizuj wejście (sanitize_text_field, absint, wp_unslash) + nonce gdzie trzeba.',
			),
			array(
				'id' => 'ajax_no_nonce',
				'severity' => 'high',
				'title' => 'AJAX: handler bez check_ajax_referer (heurystyka)',
				'pattern' => '/add_action\(\s*[\"\']wp_ajax_/i',
				'recommendation' => 'W handlerach AJAX używaj nonce: check_ajax_referer(...) lub wp_verify_nonce.',
			),
			array(
				'id' => 'danger_eval',
				'severity' => 'critical',
				'title' => 'Ryzyko: eval()',
				'pattern' => '/\beval\s*\(/i',
				'recommendation' => 'Unikaj eval. Jeśli to generator kodu – odizoluj i waliduj, najlepiej usuń.',
			),
			array(
				'id' => 'danger_base64',
				'severity' => 'medium',
				'title' => 'Podejrzane: base64_decode()',
				'pattern' => '/\bbase64_decode\s*\(/i',
				'recommendation' => 'Sprawdź czy to nie jest ukrywanie payloadu. Dla licencji/kluczy OK, ale waliduj źródło.',
			),
			array(
				'id' => 'file_upload',
				'severity' => 'high',
				'title' => 'Upload / move_uploaded_file',
				'pattern' => '/move_uploaded_file\s*\(/i',
				'recommendation' => 'Waliduj typ pliku (wp_check_filetype_and_ext), rozmiar, uprawnienia i katalog docelowy.',
			),
		);
	}

	private function security_issue($plugin_slug, $severity, $title, $details_arr, $recommendation) {
		global $wpdb;
		$severity = in_array($severity, array('low','medium','high','critical'), true) ? $severity : 'medium';
		$plugin_slug = sanitize_key((string)$plugin_slug);
		$details = $details_arr ? wp_json_encode($details_arr) : null;
		// limit: nie zapisuj nieskończenie
		static $cnt = 0;
		$cnt++;
		if ($cnt > 300) { return; }
		$wpdb->insert($this->table_issues, array(
			'created_at' => current_time('mysql'),
			'issue_type' => 'security',
			'severity' => $severity,
			'plugin_slug' => $plugin_slug,
			'title' => $title,
			'details' => $details,
			'recommendation' => $recommendation,
		));
	}
}