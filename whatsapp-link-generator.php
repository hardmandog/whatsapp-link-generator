<?php
/*
Plugin Name: WhatsApp Link Generator
Plugin URI: https://github.com/hardmandog/whatsapp-link-generator
Description: Genera enlaces de WhatsApp con el título de la página y guarda estadísticas de clics por página y fecha. Versión 3.0 muestra estadísticas ordenadas por el último clic más reciente.
Version: 3.7.0
Author: hardmandog
Author URI: https://github.com/hardmandog
License: GPLv2 or later
Text Domain: whatsapp-link-generator
*/

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'wlg_create_clicks_table');
function wlg_create_clicks_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'whatsapp_clicks';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_title VARCHAR(255) NOT NULL,
        click_date DATE NOT NULL,
        click_count INT NOT NULL DEFAULT 1,
        UNIQUE KEY unique_click (page_title(191), click_date)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function wlg_handle_click_redirect() {
    if (isset($_GET['wlg_click'])) {
        global $wpdb, $post;

        // Usar variable local para no sobreescribir el global $post
        $current_post = (isset($post) && !empty($post->post_title)) ? $post : null;

        if (!$current_post) {
            $post_id = url_to_postid($_SERVER['REQUEST_URI']);
            $current_post = get_post($post_id);
        }

        if (!$current_post || empty($current_post->post_title)) {
            wp_die('No se pudo obtener el título de la página.');
        }

        $title = $current_post->post_title;
        $date  = current_time('Y-m-d');
        $table = $wpdb->prefix . 'whatsapp_clicks';

        // Rate limiting: ignorar clics repetidos del mismo usuario en 30 min
        $cookie_key = 'wlg_clicked_' . md5($title);
        if (!isset($_COOKIE[$cookie_key])) {
            setcookie($cookie_key, '1', time() + 1800, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        // INSERT atómico: evita race condition entre SELECT y UPDATE
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (page_title, click_date, click_count)
             VALUES (%s, %s, 1)
             ON DUPLICATE KEY UPDATE click_count = click_count + 1",
            $title, $date
        ));
        } // fin rate limiting

        $whatsapp_number  = get_option('whatsapp_number', '');
        $whatsapp_message = get_option('whatsapp_message', 'Hola, deseo una cotización sobre: ');
        $text = rawurlencode($whatsapp_message . ' *' . $title . '*');
        $url  = "https://wa.me/{$whatsapp_number}?text={$text}";
        wp_redirect($url, 302);
        exit;
    }
}
add_action('template_redirect', 'wlg_handle_click_redirect');

function enlace_whatsapp_shortcode() {
    global $post;
    if (!$post || empty($post->ID)) return '';
    return esc_url(add_query_arg('wlg_click', '1', get_permalink($post->ID)));
}
add_shortcode('enlace_whatsapp', 'enlace_whatsapp_shortcode');

function whatsapp_link_generator_settings_page() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    if (isset($_POST['submit'])) {
        check_admin_referer('whatsapp_link_generator_settings');
        update_option('whatsapp_number', sanitize_text_field($_POST['whatsapp_number']));
        update_option('whatsapp_message', sanitize_textarea_field($_POST['whatsapp_message']));
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
    }
    $number = get_option('whatsapp_number', '');
    $message = get_option('whatsapp_message', 'Hola, deseo una cotización sobre: ');
    ?>
    <div class="wrap">
        <h1>Configuración WhatsApp Link Generator</h1>
        <form method="POST">
            <?php wp_nonce_field('whatsapp_link_generator_settings'); ?>
            <table class="form-table">
                <tr><th>Número:</th><td><input name="whatsapp_number" value="<?php echo esc_attr($number); ?>" class="regular-text"></td></tr>
                <tr><th>Mensaje:</th><td><textarea name="whatsapp_message" rows="3" class="large-text"><?php echo esc_textarea($message); ?></textarea></td></tr>
            </table>
            <p><input type="submit" name="submit" class="button-primary" value="Guardar cambios"></p>
        </form>
    </div>
    <?php
}

function whatsapp_link_generator_stats_page() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    global $wpdb;
    $table    = $wpdb->prefix . 'whatsapp_clicks';
    $base_url = admin_url('admin.php?page=whatsapp-link-stats');

    // --- Filtro de fechas ---
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
    if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';
    $has_filter = !empty($date_from) || !empty($date_to);

    // --- WHERE dinámico ---
    $where_parts = [];
    $where_args  = [];
    if ($date_from) { $where_parts[] = 'click_date >= %s'; $where_args[] = $date_from; }
    if ($date_to)   { $where_parts[] = 'click_date <= %s'; $where_args[] = $date_to; }
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    // --- Paginación ---
    $per_page     = 20;
    $current_page = max(1, intval(isset($_GET['paged']) ? $_GET['paged'] : 1));

    $count_sql  = "SELECT COUNT(DISTINCT page_title) FROM $table $where";
    $total_rows = $where_args
        ? intval($wpdb->get_var($wpdb->prepare($count_sql, $where_args)))
        : intval($wpdb->get_var("SELECT COUNT(DISTINCT page_title) FROM $table"));
    $total_pages  = max(1, ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    // --- Query principal ---
    $today = current_time('Y-m-d');
    if ($has_filter) {
        $sql_args = array_merge($where_args, [$per_page, $offset]);
        $sql = $wpdb->prepare(
            "SELECT page_title,
                    SUM(click_count)  AS total_clicks,
                    MIN(click_date)   AS primer_click,
                    MAX(click_date)   AS ultimo_click
             FROM $table $where
             GROUP BY page_title
             ORDER BY total_clicks DESC
             LIMIT %d OFFSET %d",
            $sql_args
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT page_title,
                    SUM(click_count) AS total_clicks,
                    SUM(CASE WHEN click_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN click_count ELSE 0 END) AS clicks_30d,
                    SUM(CASE WHEN click_date = %s THEN click_count ELSE 0 END) AS clicks_today,
                    MIN(click_date)  AS primer_click,
                    MAX(click_date)  AS ultimo_click
             FROM $table
             GROUP BY page_title
             ORDER BY total_clicks DESC
             LIMIT %d OFFSET %d",
            [$today, $per_page, $offset]
        );
    }
    $agrupados = $wpdb->get_results($sql, ARRAY_A);

    // --- Clics de hoy (siempre del día real) ---
    $clics_hoy = intval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(click_count) FROM $table WHERE click_date = %s", $today
    )));

    $num_cols = $has_filter ? 5 : 7;
    ?>
    <div class="wrap">
        <h1>Estadísticas — WhatsApp Link Generator</h1>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-success is-dismissible"><p>Historial eliminado correctamente.</p></div>
        <?php endif; ?>

        <!-- Clics de hoy -->
        <div style="background:#fff;border-left:4px solid #25D366;padding:10px 16px;margin:16px 0;display:inline-block;border-radius:2px;box-shadow:0 1px 3px rgba(0,0,0,.1)">
            Clics hoy: <strong style="font-size:1.4em;color:#25D366"><?php echo $clics_hoy; ?></strong>
        </div>

        <!-- Filtro de fechas + Export CSV -->
        <form method="GET" style="margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input type="hidden" name="page" value="whatsapp-link-stats">
            <label>Desde: <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></label>
            <label>Hasta: <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>"></label>
            <button type="submit" class="button">Filtrar</button>
            <?php if ($has_filter): ?>
                <a href="<?php echo esc_url($base_url); ?>" class="button">Limpiar</a>
            <?php endif; ?>
            <a href="<?php
                $csv_args = ['action' => 'wlg_export_csv', '_wpnonce' => wp_create_nonce('wlg_export_csv')];
                if ($date_from) $csv_args['date_from'] = $date_from;
                if ($date_to)   $csv_args['date_to']   = $date_to;
                echo esc_url(add_query_arg($csv_args, admin_url('admin-post.php')));
            ?>" class="button button-secondary" style="margin-left:auto">&#11015; Exportar CSV</a>
        </form>

        <!-- Tabla -->
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Página</th>
                    <th><?php echo $has_filter ? 'Clics (rango)' : 'Total clics'; ?></th>
                    <?php if (!$has_filter): ?>
                        <th>Últimos 30 días</th>
                        <th>Hoy</th>
                    <?php endif; ?>
                    <th>Primer clic</th>
                    <th>Último clic</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($agrupados)): ?>
                <tr><td colspan="<?php echo $num_cols; ?>">Sin datos para mostrar.</td></tr>
            <?php else: foreach ($agrupados as $row):
                $active_today = (!$has_filter && intval($row['clicks_today']) > 0);
            ?>
                <tr<?php echo $active_today ? ' style="background:#f0fff4"' : ''; ?>>
                    <td><?php echo esc_html($row['page_title']); ?></td>
                    <td><strong><?php echo esc_html($row['total_clicks']); ?></strong></td>
                    <?php if (!$has_filter): ?>
                        <td><?php echo esc_html($row['clicks_30d']); ?></td>
                        <td><?php echo $active_today
                            ? '<strong style="color:#25D366">' . esc_html($row['clicks_today']) . '</strong>'
                            : '0'; ?>
                        </td>
                    <?php endif; ?>
                    <td><?php echo esc_html($row['primer_click']); ?></td>
                    <td><?php echo esc_html($row['ultimo_click']); ?></td>
                    <td>
                        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('¿Borrar todo el historial de esta página? Esta acción no se puede deshacer.')">
                            <?php wp_nonce_field('wlg_delete_page_' . md5($row['page_title'])); ?>
                            <input type="hidden" name="action" value="wlg_delete_page_stats">
                            <input type="hidden" name="page_title" value="<?php echo esc_attr($row['page_title']); ?>">
                            <button type="submit" class="button button-small" style="color:#a00">Borrar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Paginación -->
        <?php if ($total_pages > 1): ?>
        <div style="margin-top:16px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <?php for ($i = 1; $i <= $total_pages; $i++):
                $pg_url = add_query_arg(['paged' => $i, 'date_from' => $date_from, 'date_to' => $date_to], $base_url);
                $active = ($i === $current_page);
            ?>
                <a href="<?php echo esc_url($pg_url); ?>"
                   style="padding:4px 10px;border:1px solid #ccc;border-radius:3px;text-decoration:none;<?php echo $active ? 'background:#25D366;color:#fff;border-color:#25D366' : 'background:#fff;color:#333'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            <span style="color:#666;margin-left:6px">Página <?php echo $current_page; ?> de <?php echo $total_pages; ?> (<?php echo $total_rows; ?> páginas)</span>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// Exportar CSV
function wlg_export_csv() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('wlg_export_csv');
    global $wpdb;
    $table = $wpdb->prefix . 'whatsapp_clicks';

    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
    if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

    $where_parts = [];
    $where_args  = [];
    if ($date_from) { $where_parts[] = 'click_date >= %s'; $where_args[] = $date_from; }
    if ($date_to)   { $where_parts[] = 'click_date <= %s'; $where_args[] = $date_to; }
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $base_sql = "SELECT page_title, SUM(click_count) AS total_clicks, MIN(click_date) AS primer_click, MAX(click_date) AS ultimo_click FROM $table $where GROUP BY page_title ORDER BY total_clicks DESC";
    $rows = $where_args
        ? $wpdb->get_results($wpdb->prepare($base_sql, $where_args), ARRAY_A)
        : $wpdb->get_results($base_sql, ARRAY_A);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wlg-estadisticas-' . current_time('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; // BOM para Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Página', 'Total clics', 'Primer clic', 'Último clic']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['page_title'], $row['total_clicks'], $row['primer_click'], $row['ultimo_click']]);
    }
    fclose($out);
    exit;
}
add_action('admin_post_wlg_export_csv', 'wlg_export_csv');

// Borrar historial de una página
function wlg_delete_page_stats() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    $page_title = isset($_POST['page_title']) ? sanitize_text_field($_POST['page_title']) : '';
    if (empty($page_title)) wp_die('Título inválido.');
    check_admin_referer('wlg_delete_page_' . md5($page_title));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'whatsapp_clicks', ['page_title' => $page_title], ['%s']);
    wp_redirect(add_query_arg(['page' => 'whatsapp-link-stats', 'deleted' => '1'], admin_url('admin.php')));
    exit;
}
add_action('admin_post_wlg_delete_page_stats', 'wlg_delete_page_stats');

function whatsapp_link_generator_menu() {
    add_menu_page('WhatsApp Link Generator', 'WhatsApp Link', 'manage_options', 'whatsapp-link-generator', 'whatsapp_link_generator_settings_page', 'dashicons-whatsapp', 25);
    add_submenu_page('whatsapp-link-generator', 'Instrucciones', 'Instrucciones', 'manage_options', 'wlg-help', 'wlg_help_page');
    add_submenu_page('whatsapp-link-generator', 'Estadísticas', 'Estadísticas', 'manage_options', 'whatsapp-link-stats', 'whatsapp_link_generator_stats_page');
}
add_action('admin_menu', 'whatsapp_link_generator_menu');


function wlg_help_page() {
    echo '<div class="wrap"><h1>Instrucciones de uso</h1>
    <p><strong>Shortcode:</strong> Usa <code>[enlace_whatsapp]</code> para generar un enlace rastreable. Este enlace:</p>
    <ul>
        <li>Redirige automáticamente a WhatsApp</li>
        <li>Registra el clic en el panel de estadísticas</li>
    </ul>
    <p><strong>Importante:</strong> No uses el shortcode directamente dentro de un atributo <code>href=""</code>. Si tu constructor (como Elementor) no interpreta shortcodes en botones, copia la URL generada (ej. <code>https://tusitio.com/?wlg_click=1</code>) y pégala manualmente.</p>
    </div>';
}

// ─── Auto-updater desde GitHub ──────────────────────────────────────────────
require_once __DIR__ . '/class-github-updater.php';
new GitHub_Updater( __FILE__, 'hardmandog', 'whatsapp-link-generator' );