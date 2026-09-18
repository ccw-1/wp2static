<?php
/**
 * Plugin Name:        WP2Static
 * Description:        Crawls your WordPress site and exports a self-contained static version to an output directory. HTML is saved as static files, the client-side script keeps appearance and behavior, and forms keep POSTing to live PHP endpoints so submissions still work.
 * Version:            0.4.0
 * Author:             CCW-1
 * Author URI:         https://github.com/ccw-1/wp2static
 * Requires at least:  5.5
 * Requires PHP:       7.4
 * Text Domain:        wp2static
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP2STATIC_VERSION', '0.4.0' );
define( 'WP2STATIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP2STATIC_URL', plugin_dir_url( __FILE__ ) );

require_once WP2STATIC_DIR . 'inc/class-wp2static-crawler.php';

class Wp2static {

	const OPTION = 'wp2static_opts';
	const LOG    = 'wp2static_last_log';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
		add_action( 'admin_post_wp2static_export', array( __CLASS__, 'handle_export' ) );
		add_filter( 'plugin_action_links_wp2static/wp2static.php', array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=wp2static' ) ) . '">' . esc_html__( 'Settings', 'wp2static' ) . '</a>';
		return $links;
	}

	public static function defaults() {
		return array(
			'output_dir'     => dirname( ABSPATH ) . '/wp2static-export',
			'depth'          => 3,
			'max_pages'      => 200,
			'fetch_assets'   => 1,
			'site_aliases'   => '',
			'aliases_normalize' => 1,
			'exclude'        => "/wp-admin/\n/wp-login.php\n/wp-json/\n\.php\n",
			'extra_rewrites' => '',
			'extra_js'       => '',
		);
	}

	public static function get_opts() {
		$opts = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
	}

	public static function admin_menu() {
		add_options_page(
			__( 'WP2Static Export', 'wp2static' ),
			__( 'WP2Static', 'wp2static' ),
			'manage_options',
			'wp2static',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function admin_init() {
		register_setting( 'wp2static_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = array(
			'output_dir'     => isset( $input['output_dir'] ) ? trim( $input['output_dir'] ) : $defaults['output_dir'],
			'depth'          => isset( $input['depth'] ) ? max( 1, absint( $input['depth'] ) ) : $defaults['depth'],
			'max_pages'      => isset( $input['max_pages'] ) ? max( 1, absint( $input['max_pages'] ) ) : $defaults['max_pages'],
			'fetch_assets'   => empty( $input['fetch_assets'] ) ? 0 : 1,
			'site_aliases'   => isset( $input['site_aliases'] ) ? trim( $input['site_aliases'] ) : $defaults['site_aliases'],
			'aliases_normalize' => empty( $input['aliases_normalize'] ) ? 0 : 1,
			'exclude'        => isset( $input['exclude'] ) ? trim( $input['exclude'] ) : $defaults['exclude'],
			'extra_rewrites' => isset( $input['extra_rewrites'] ) ? trim( $input['extra_rewrites'] ) : $defaults['extra_rewrites'],
			'extra_js'       => isset( $input['extra_js'] ) ? trim( $input['extra_js'] ) : $defaults['extra_js'],
		);
		return $out;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wp2static_export' );

		set_time_limit( 0 );
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '1G' );
			ini_set( 'max_execution_time', '0' );
		}

		$crawler = new Wp2static_Crawler( self::get_opts() );
		$log     = $crawler->run();

		update_option( self::LOG, array(
			'time' => current_time( 'mysql' ),
			'log'  => $log,
		) );

		wp_safe_redirect( add_query_arg( array(
			'page'   => 'wp2static',
			'export' => '1',
		), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render_page() {
		$opts = self::get_opts();
		$last = get_option( self::LOG, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP2Static Export', 'wp2static' ); ?></h1>

			<?php if ( ! empty( $_GET['export'] ) ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'Export finished.', 'wp2static' ); ?></strong>
					<?php echo esc_html( current_time( 'mysql' ) ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wp2static_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wp2static_output"><?php esc_html_e( 'Output directory', 'wp2static' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="wp2static_output"
							       name="<?php echo esc_attr( self::OPTION ); ?>[output_dir]"
							       value="<?php echo esc_attr( $opts['output_dir'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Absolute filesystem path where the static site is written.', 'wp2static' ); ?>
								<?php echo esc_html( ( is_dir( $opts['output_dir'] ) && is_writable( $opts['output_dir'] ) ) ? __( 'Directory ok.', 'wp2static' ) : __( 'Directory missing or not writable.', 'wp2static' ) ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_depth"><?php esc_html_e( 'Max crawl depth', 'wp2static' ); ?></label></th>
						<td>
							<input type="number" min="1" max="20" id="wp2static_depth"
							       name="<?php echo esc_attr( self::OPTION ); ?>[depth]"
							       value="<?php echo esc_attr( $opts['depth'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_max"><?php esc_html_e( 'Max pages', 'wp2static' ); ?></label></th>
						<td>
							<input type="number" min="1" max="5000" id="wp2static_max"
							       name="<?php echo esc_attr( self::OPTION ); ?>[max_pages]"
							       value="<?php echo esc_attr( $opts['max_pages'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Self-contained assets', 'wp2static' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[fetch_assets]" value="1"
								       <?php checked( $opts['fetch_assets'] ); ?> />
								<?php esc_html_e( 'Download CSS/JS/images into the export (self-hosted only; CDN stays absolute).', 'wp2static' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_aliases"><?php esc_html_e( 'Site aliases', 'wp2static' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="3" id="wp2static_aliases"
							          name="<?php echo esc_attr( self::OPTION ); ?>[site_aliases]"><?php echo esc_textarea( $opts['site_aliases'] ); ?></textarea>
							<p class="description">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[aliases_normalize]" value="1"
									       <?php checked( $opts['aliases_normalize'] ); ?> />
									<?php esc_html_e( 'Also treat http/https and www/non-www variants of the crawl origin as the same site.', 'wp2static' ); ?>
								</label><br />
								<?php esc_html_e( 'One full URL or bare hostname per line (e.g. https://staging.example.com or example.com). Links to these hosts are rewritten to relative paths and their assets are downloaded, instead of being left absolute because the host differs from the crawl origin.', 'wp2static' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_extra"><?php esc_html_e( 'Host-relative path rewrites', 'wp2static' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="3" id="wp2static_extra"
							          name="<?php echo esc_attr( self::OPTION ); ?>[extra_rewrites]"><?php echo esc_textarea( $opts['extra_rewrites'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One root-relative path per line (e.g. /request-quote/). These are rewritten from absolute-home URLs to host-relative paths in the exported HTML, so AJAX endpoints called from the static copy stay same-origin (browsers CORS-block cross-origin XHR). /wp-admin/admin-ajax.php is always rewritten.', 'wp2static' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_extrajs"><?php esc_html_e( 'Extra JavaScript', 'wp2static' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="5" id="wp2static_extrajs"
							          name="<?php echo esc_attr( self::OPTION ); ?>[extra_js]"><?php echo esc_textarea( $opts['extra_js'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional JavaScript appended to the exported wp2static.js on every page. Theme-specific behaviour (e.g. re-randomizing a Divi math captcha on the contact form) goes here so the plugin itself stays theme-agnostic.', 'wp2static' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp2static_exclude"><?php esc_html_e( 'Exclude patterns', 'wp2static' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="5" id="wp2static_exclude"
							          name="<?php echo esc_attr( self::OPTION ); ?>[exclude]"><?php echo esc_textarea( $opts['exclude'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One regex per line (no delimiters). Matched against the full URL. Never exported: wp-admin, wp-login, wp-json, admin-ajax, feeds and file extensions.', 'wp2static' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'wp2static' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp2static_export" />
				<?php wp_nonce_field( 'wp2static_export' ); ?>
				<h2><?php esc_html_e( 'Generate static copy', 'wp2static' ); ?></h2>
				<p class="description">
					<?php echo esc_html( sprintf(
						/* translators: %s: site URL */
						__( 'Crawls %s via the web and writes a static version of every internal page found. Each page is downloaded (cached pages load fast), internal links are rewritten to .html files, forms keep posting to the live PHP endpoints, and %s links to assets is injected on every page.', 'wp2static' ),
						home_url(),
						esc_html( 'wp2static.js' )
					) ); ?>
				</p>
				<?php submit_button( __( 'Run export now', 'wp2static' ), 'primary', 'submit', true ); ?>
			</form>

			<?php if ( ! empty( $last['log'] ) ) : ?>
				<h2><?php esc_html_e( 'Last run', 'wp2static' ); ?></h2>
				<p class="description"><?php echo esc_html( $last['time'] ); ?></p>
				<pre class="wp2static-log" style="max-height:400px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;"><?php echo esc_html( implode( "\n", $last['log'] ) ); ?></pre>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No export run yet.', 'wp2static' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

Wp2static::init();