<?php
/**
 * WP2Static crawler and exporter.
 *
 * Crawls the live site, downloads every internal HTML page through the web
 * (so the exported copy mirrors exactly what a visitor sees, including any
 * page cache), rewrites internal links to local .html files, downloads
 * self-hosted assets, redirects form submissions back to the live PHP
 * endpoints, and injects a small client-side script on each page.
 *
 * @link https://github.com/ccw-1/wp2static
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wp2static_Crawler {

	private $origin;
	private $origin_host;
	private $out_dir;
	private $depth;
	private $max_pages;
	private $exclude;
	private $fetch_assets;
	private $extra_js;

	private $queue  = array();
	private $queued = array();
	private $visited = array();
	private $saved  = array();
	private $assets = array();
	private $fresh  = true;
	private $script_rewrites = array( '/wp-admin/admin-ajax.php' );
	public  $log    = array();

	public function __construct( $opts = array() ) {
		$this->origin       = untrailingslashit( home_url() );
		$this->origin_host  = strtolower( (string) wp_parse_url( $this->origin, PHP_URL_HOST ) );
		$this->out_dir      = rtrim( (string) ( $opts['output_dir'] ?? '' ), '/\\' );
		$this->depth        = max( 1, absint( $opts['depth'] ?? 3 ) );
		$this->max_pages    = max( 1, absint( $opts['max_pages'] ?? 200 ) );
		$this->fetch_assets = ! empty( $opts['fetch_assets'] );
		$this->extra_js     = (string) ( $opts['extra_js'] ?? '' );

		foreach ( preg_split( '/\r?\n/', (string) ( $opts['extra_rewrites'] ?? '' ) ) as $path ) {
			$path = trim( (string) $path, " \t\r\n/" );
			if ( '' !== $path ) {
				$this->script_rewrites[] = '/' . $path;
			}
		}

		$lines = $opts['exclude'] ?? '';
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/\r?\n/', $lines );
		}
		$this->exclude = array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	/**
	 * Run a batch of the export. Returns a result array with 'more' (bool).
	 *
	 * State (pending queue + visited set) is persisted to a JSON file in the
	 * output dir after every page, so a batch that is interrupted is resumed
	 * by the next call. Keep batches small so each HTTP request stays short
	 * (the host watchdog SIGTERMs long-running lsphp processes).
	 */
	public function run( $batch = 5 ) {
		$this->log( 'Origin: ' . $this->origin );
		$this->log( 'Output: ' . $this->out_dir );

		if ( empty( $this->origin_host ) ) {
			$this->log( 'ERROR: invalid origin host.' );
			return array( 'more' => false, 'log' => $this->log );
		}
		if ( ! wp_mkdir_p( $this->out_dir ) ) {
			$this->log( 'ERROR: cannot create output directory ' . $this->out_dir );
			return array( 'more' => false, 'log' => $this->log );
		}

		$this->load_state();
		if ( $this->fresh ) {
			@file_put_contents( $this->out_dir . '/wp2static-progress.log', '' );
			$this->enqueue( $this->origin . '/?wp2static=1', 0 );
		}

		$batch = max( 1, absint( $batch ) );
		$pages = 0;
		while ( ! empty( $this->queue ) && $pages < $batch && count( $this->saved ) < $this->max_pages ) {
			$item = array_shift( $this->queue );
			$url  = $item['url'];
			$key  = $this->path_of( $url );
			if ( isset( $this->visited[ $key ] ) ) {
				continue;
			}
			$this->visited[ $key ] = true;

			$html = $this->fetch( $url );
			if ( '' === $html ) {
				$this->log( 'SKIP (failed/non-HTML): ' . $url );
				$this->save_state();
				continue;
			}

			$pages++;
			$this->discover_links( $html, $url, $item['depth'] );

			$content = $this->inject_js( $this->transform( $html, $url ), $url );
			$file    = $this->page_file( $url );
			$result  = $this->write( $file, $content );
			$this->log( sprintf( 'SAVED [%s/%s] %s -> %s (%d bytes)', count( $this->saved ) + 1, $this->max_pages, $url, $file, $result ) );
			if ( $result > 0 ) {
				$this->saved[] = $file;
			}
			$this->save_state();
		}

		$this->install_js();

		$more = ! empty( $this->queue ) && count( $this->saved ) < $this->max_pages;
		$this->log( 'batch done: pages=' . $pages . ' saved=' . count( $this->saved ) . ' more=' . ( $more ? 'yes' : 'no' ) );
		if ( ! $more ) {
			$this->write( 'wp2static-export.log.txt', implode( "\n", $this->log ) );
			$this->log( 'Done: ' . count( $this->saved ) . ' files written (pages + helper files).' );
		}
		return array( 'more' => $more, 'saved' => $this->saved, 'log' => $this->log );
	}

	private function state_file() {
		return $this->out_dir . '/wp2static-state.json';
	}

	private function load_state() {
		$this->fresh  = ! file_exists( $this->state_file() );
		$this->saved  = array();
		$this->queue  = array();
		$this->visited = array();
		$this->queued  = array();
		if ( $this->fresh ) {
			return;
		}
		$json = @file_get_contents( $this->state_file() );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			$this->fresh = true;
			return;
		}
		$this->queue   = isset( $data['queue'] ) && is_array( $data['queue'] ) ? $data['queue'] : array();
		$this->visited = isset( $data['visited'] ) && is_array( $data['visited'] ) ? $data['visited'] : array();
		$this->queued  = isset( $data['queued'] ) && is_array( $data['queued'] ) ? $data['queued'] : array();
		$this->saved   = isset( $data['saved'] ) && is_array( $data['saved'] ) ? $data['saved'] : array();
	}

	private function save_state() {
		if ( empty( $this->out_dir ) ) {
			return;
		}
		$data = array(
			'queue'   => $this->queue,
			'visited' => $this->visited,
			'queued'  => $this->queued,
			'saved'   => $this->saved,
		);
		file_put_contents( $this->state_file(), wp_json_encode( $data ) );
	}

	private function log( $line ) {
		$this->log[] = $line;
		if ( ! empty( $this->out_dir ) ) {
			@file_put_contents( $this->out_dir . '/wp2static-progress.log', $line . "\n", FILE_APPEND | LOCK_EX );
		}
	}

	/* ----------------------------------
	 * HTTP
	 * ---------------------------------- */

	private function fetch( $url ) {
		$code = 0;
		$resp = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$resp = wp_remote_get( $url, array(
				'timeout'     => 30,
				'redirection' => 5,
				'sslverify'   => false,
				'user-agent'  => 'wp2static/' . WP2STATIC_VERSION,
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			) );
			if ( is_wp_error( $resp ) ) {
				break;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			if ( in_array( $code, array( 500, 502, 503, 429 ), true ) && $attempt < 3 ) {
				$this->log( 'HTTP ' . $code . ' (retry ' . $attempt . '): ' . $url );
				sleep( 2 );
				continue;
			}
			break;
		}
		if ( is_wp_error( $resp ) ) {
			$this->log( 'FETCH ERROR ' . $url . ': ' . $resp->get_error_message() );
			return '';
		}
		if ( 200 !== $code ) {
			$this->log( 'HTTP ' . $code . ': ' . $url );
			return '';
		}
		$type = wp_remote_retrieve_header( $resp, 'content-type' );
		if ( false === stripos( (string) $type, 'html' ) ) {
			return '';
		}
		return wp_remote_retrieve_body( $resp );
	}

	private function fetch_asset( $url ) {
		$resp = wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 5,
			'sslverify'   => false,
			'user-agent'  => 'wp2static/' . WP2STATIC_VERSION,
		) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $resp );
	}

	/* ----------------------------------
	 * Discovery
	 * ---------------------------------- */

	private function enqueue( $url, $depth ) {
		if ( $depth > $this->depth ) {
			return;
		}
		if ( $this->is_excluded( $url ) ) {
			return;
		}
		$key = $this->path_of( $url );
		if ( isset( $this->visited[ $key ] ) || isset( $this->queued[ $key ] ) ) {
			return;
		}
		$this->queued[ $key ] = true;
		$this->queue[]        = array( 'url' => $url, 'depth' => $depth );
	}

	private function discover_links( $html, $page_url, $depth ) {
		$doc = $this->load_dom( $html );
		if ( ! $doc ) {
			return;
		}
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$abs = $this->resolve( $a->getAttribute( 'href' ), $page_url );
			if ( '' === $abs || ! $this->is_internal( $abs ) ) {
				continue;
			}
			$this->enqueue( $abs, $depth + 1 );
		}
	}

	/* ----------------------------------
	 * URL helpers
	 * ---------------------------------- */

	private function resolve( $href, $page_url ) {
		$h = trim( (string) $href );
		if ( '' === $h || preg_match( '/^(#|javascript:|mailto:|tel:|data:)/i', $h ) ) {
			return '';
		}
		$p = parse_url( $h );
		if ( ! empty( $p['scheme'] ) ) {
			if ( ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
				return '';
			}
			return $h;
		}
		$base = parse_url( $page_url );
		$host = isset( $base['host'] ) ? $base['host'] : $this->origin_host;
		$scheme = isset( $base['scheme'] ) ? $base['scheme'] : 'https';
		if ( 0 === strpos( $h, '//' ) ) {       // protocol-relative
			return $scheme . ':' . $h;
		}
		if ( 0 === strpos( $h, '/' ) ) {        // absolute path
			return $scheme . '://' . $host . $h;
		}
		$dir = '/';
		if ( isset( $base['path'] ) && strlen( $base['path'] ) > 1 ) {
			$dir = preg_replace( '#/[^/]*$#', '/', $base['path'] );
		}
		return $scheme . '://' . $host . $dir . $h;  // relative path
	}

	private function is_internal( $url ) {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return '' !== $host && $host === $this->origin_host;
	}

	private function path_of( $url ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );
		return rtrim( $path, '/' ) . ( '/' === $path ? '' : '' );
	}

	private function under_output_dir( $path ) {
		$docroot = rtrim( (string) ABSPATH, '/' );
		if ( empty( $this->out_dir ) || 0 !== strpos( $this->out_dir, $docroot . '/' ) ) {
			return false; // output outside the docroot cannot be crawled anyway
		}
		$rel = '/' . trim( substr( $this->out_dir, strlen( $docroot ) ), '/' );
		return ( $path === $rel ) || ( 0 === strpos( $path, $rel . '/' ) );
	}

	private function fragment_of( $url ) {
		$f = parse_url( $url, PHP_URL_FRAGMENT );
		return null === $f ? '' : '#' . $f;
	}

	private function is_excluded( $url ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/(^|\/)(wp-admin|wp-includes|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|\.php)(\/|$)/i', $path ) ) {
			return true;
		}
		if ( preg_match( '/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|zip|pdf)(\?.*)?$/i', $path ) ) {
			return true;
		}
		if ( $this->under_output_dir( $path ) ) {
			return true;
		}
		foreach ( $this->exclude as $pat ) {
			if ( '' === $pat ) {
				continue;
			}
			if ( @preg_match( '~' . str_replace( '~', '\~', $pat ) . '~', $url ) ) {
				return true;
			}
		}
		return false;
	}

	/* ----------------------------------
	 * File mapping
	 * ---------------------------------- */

	private function page_file( $url ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( '' === $path || '/' === $path ) {
			return 'index.html';
		}
		return ltrim( rtrim( $path, '/' ), '/' ) . '/index.html';
	}

	private function relative_to( $local_target, $page_url ) {
		$current = $this->page_file( $page_url );
		$cur     = dirname( $current );
		$cur     = ( '.' === $cur ) ? array() : explode( '/', $cur );
		$target  = explode( '/', trim( $local_target, '/' ) );
		$i       = 0;
		while ( isset( $cur[ $i ] ) && isset( $target[ $i ] ) && $cur[ $i ] === $target[ $i ] ) {
			$i++;
		}
		$rel = str_repeat( '../', count( $cur ) - $i ) . implode( '/', array_slice( $target, $i ) );
		return '' === $rel ? './' : $rel;
	}

	private function local_asset( $url ) {
		if ( isset( $this->assets[ $url ] ) ) {
			return $this->assets[ $url ];
		}
		$name = md5( $url ) . $this->asset_ext( $url );
		$dir  = $this->out_dir . '/assets';
		$file = $dir . '/' . $name;
		if ( ! file_exists( $file ) ) {
			$body = $this->fetch_asset( $url );
			if ( false === $body ) {
				$this->log( 'ASSET FAILED: ' . $url );
				$this->assets[ $url ] = '';
				return '';
			}
			wp_mkdir_p( $dir );
			file_put_contents( $file, $body );
		}
		$this->assets[ $url ] = 'assets/' . $name;
		return $this->assets[ $url ];
	}

	private function asset_ext( $url ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map', 'webmanifest' ), true ) ) {
			return '.' . $ext;
		}
		return '';
	}

	/* ----------------------------------
	 * DOM transform
	 * ---------------------------------- */

	private function load_dom( $html ) {
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $doc;
	}

	private function transform( $html, $page_url ) {
		$doc = $this->load_dom( $html );
		if ( ! $doc ) {
			return $html;
		}

		foreach ( $doc->childNodes as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$doc->removeChild( $node );
			}
		}

		$xpath = new DOMXPath( $doc );

		foreach ( $xpath->query( '//a[@href]' ) as $a ) {
			$abs = $this->resolve( $a->getAttribute( 'href' ), $page_url );
			if ( '' === $abs || ! $this->is_internal( $abs ) ) {
				continue;
			}
			if ( $this->is_excluded( $abs ) ) {
				$a->setAttribute( 'href', $abs ); // keep excluded targets pointed at the live site
				continue;
			}
			$a->setAttribute( 'href', $this->relative_to( $this->page_file( $abs ), $page_url ) . $this->fragment_of( $abs ) );
		}

		foreach ( $xpath->query( '//form' ) as $f ) {
			$action = trim( $f->getAttribute( 'action' ) );
			if ( '' === $action ) {
				$f->setAttribute( 'action', $this->origin . (string) parse_url( $page_url, PHP_URL_PATH ) );
			} elseif ( 0 === strpos( $action, '/' ) ) {
				$f->setAttribute( 'action', $this->origin . $action );
			} elseif ( ! preg_match( '/^(https?:)?\/\//i', $action ) ) {
				$base = parse_url( $page_url );
				$dir  = '/';
				if ( isset( $base['path'] ) && strlen( $base['path'] ) > 1 ) {
					$dir = preg_replace( '#/[^/]*$#', '/', $base['path'] );
				}
				$f->setAttribute( 'action', $this->origin . $dir . $action );
			}
		}

		foreach ( $xpath->query( '//img[@src]' ) as $n ) {
			$this->rewrite_asset( $n, 'src', $page_url );
		}
		foreach ( $xpath->query( '//script[@src]' ) as $n ) {
			$this->rewrite_asset( $n, 'src', $page_url );
		}
		foreach ( $xpath->query( '//link[contains(concat(" ",normalize-space(@rel)," ")," stylesheet ")]' ) as $n ) {
			$this->rewrite_asset( $n, 'href', $page_url );
		}

		return $this->rewrite_script_urls( $doc->saveHTML() );
	}

	/**
	 * AJAX and session-sensitive endpoints are injected into inline scripts as
	 * absolute `$origin` URLs (wp_localize_script). If the static copy is served
	 * from a different host (e.g. bare vs. www) than that origin, the browser
	 * will CORS-block the XHR before the live WP site can answer, so the button
	 * silently does nothing. Rewriting these to host-relative paths keeps them
	 * same-origin wherever the export is hosted.
	 */
	private function rewrite_script_urls( $html ) {
		$bases = array( $this->origin );
		$www   = (string) preg_replace( '#^(https?)://#i', '$1://www.', $this->origin );
		if ( $www !== $this->origin ) {
			$bases[] = $www;
		}
		foreach ( $bases as $base ) {
			$from = array();
			$to   = array();
			foreach ( $this->script_rewrites as $path ) {
				$from[] = $base . $path;
				$to[]   = $path;
			}
			$html = str_replace( $from, $to, $html );
		}
		return $html;
	}

	private function rewrite_asset( $node, $attr, $page_url ) {
		$src = trim( $node->getAttribute( $attr ) );
		if ( '' === $src || preg_match( '/^(#|data:|about:|blob:)/i', $src ) ) {
			return;
		}
		$abs = $this->resolve( $src, $page_url );
		if ( '' === $abs ) {
			return;
		}
		if ( ! $this->fetch_assets ) {
			return; // keep the live absolute URL so the static copy still renders
		}
		if ( ! $this->is_internal( $abs ) ) {
			return; // CDN / external stays absolute
		}
		$local = $this->local_asset( $abs );
		if ( '' !== $local ) {
			$node->setAttribute( $attr, $this->relative_to( $local, $page_url ) );
		}
	}

	private function install_js() {
		$src = WP2STATIC_DIR . 'assets/wp2static.js';
		if ( ! file_exists( $src ) ) {
			return;
		}
		$content = file_get_contents( $src );
		if ( '' !== $this->extra_js ) {
			$content .= "\n/* wp2static extra_js */\n" . $this->extra_js . "\n";
		}
		file_put_contents( $this->out_dir . '/wp2static.js', $content );
		$this->log( 'Installed wp2static.js in output root.' );
	}

	private function inject_js( $html, $page_url ) {
		$rel = $this->relative_to( 'wp2static.js', $page_url );
		$tag = '<script src="' . esc_attr( $rel ) . '" data-wp2static-origin="' . esc_attr( $this->origin ) . '" defer></script>';
		if ( preg_match( '#</body>#i', $html ) ) {
			return preg_replace( '#</body>#i', $tag . '</body>', $html, 1 );
		}
		return $html . $tag;
	}

	/* ----------------------------------
	 * Output
	 * ---------------------------------- */

	private function write( $file, $content ) {
		$path = $this->out_dir . '/' . ltrim( $file, '/' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( false === file_put_contents( $path, $content ) ) {
			$this->log( 'WRITE FAILED: ' . $path );
			return 0;
		}
		return (int) filesize( $path );
	}
}