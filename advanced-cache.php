<?php
/**
 * Lite Page Cache - Drop-in fuer wp-content/advanced-cache.php
 *
 * WOZU DIESE DATEI, wenn das Plugin dasselbe schon kann: Der Serve-Zweig im
 * Plugin haengt an template_redirect. Bis dorthin sind wp-settings.php, alle
 * Mu-Plugins, alle Plugins, das Theme und die Hauptabfrage gelaufen - fuer
 * eine Antwort, die vollstaendig auf der Platte liegt. Diese Datei wird von
 * wp-settings.php direkt nach wp-config.php eingebunden, also bevor davon
 * irgendetwas existiert, und liefert den Treffer dort aus.
 *
 * DESHALB KOMMT HIER KEINE WORDPRESS-FUNKTION VOR. Weder home_url() noch
 * is_ssl(), is_user_logged_in() oder wp_parse_url(). Alles, was das Plugin
 * aus WordPress holt, steht in lpc-config.php, und das schreibt das Plugin.
 *
 * DER SCHLUESSEL MUSS DERSELBE SEIN wie in Lite_Page_Cache::get_cache_file_path().
 * Weicht er ab, findet dieser Weg nichts und das Plugin schreibt weiter -
 * der Cache waere dann still wirkungslos statt kaputt.
 *
 * @package lite-page-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// LPC-DROPIN 1.1.0

if ( ! function_exists( 'lpc_dropin_ausliefern' ) ) {

	function lpc_dropin_ausliefern() {

		if ( ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
			return;
		}

		$verz  = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __FILE__ ) ) . '/cache/lite-page-cache/';
		$konfd = $verz . 'lpc-config.php';
		if ( ! is_readable( $konfd ) ) {
			return;
		}

		$konf = include $konfd;
		if ( ! is_array( $konf ) || empty( $konf['aktiv'] ) || empty( $konf['host'] ) ) {
			return;
		}

		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] || ! empty( $_GET ) ) {
			return;
		}

		// Das Plugin fragt is_user_logged_in(); hier gibt es die Funktion noch
		// nicht, geprueft wird also das Merkmal, aus dem WordPress sie ableitet.
		if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $name ) {
				if ( 0 === strpos( $name, 'wordpress_logged_in_' )
					|| 0 === strpos( $name, 'wp-postpass_' )
					|| 0 === strpos( $name, 'comment_author_' ) ) {
					return;
				}
			}
		}

		$angefragt = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		$angefragt = preg_replace( '/:\d+$/', '', $angefragt );
		if ( '' === $angefragt || $angefragt !== $konf['host'] ) {
			return;
		}

		// is_ssl() Zeile fuer Zeile nachgebaut: der Port zaehlt NUR, wenn
		// $_SERVER['HTTPS'] gar nicht gesetzt ist. Eine Abweichung hier erzeugt
		// einen zweiten Schluessel fuer dieselbe Seite.
		$https = false;
		if ( isset( $_SERVER['HTTPS'] ) ) {
			$h = strtolower( (string) $_SERVER['HTTPS'] );
			if ( 'on' === $h || '1' === (string) $_SERVER['HTTPS'] ) {
				$https = true;
			}
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) {
			$https = true;
		}

		$pfad  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$frage = strpos( $pfad, '?' );
		if ( false !== $frage ) {
			$pfad = substr( $pfad, 0, $frage );
		}

		$datei = $verz . md5( ( $https ? 'https' : 'http' ) . '://' . $angefragt . $pfad ) . '.html';
		if ( ! is_readable( $datei ) ) {
			return;
		}

		$groesse = (int) @filesize( $datei );
		$mtime   = (int) @filemtime( $datei );
		if ( $groesse <= 0 || $mtime <= 0 ) {
			return;
		}

		$min = isset( $konf['min_bytes'] ) ? (int) $konf['min_bytes'] : 300;
		if ( $groesse < $min ) {
			return;
		}

		// Abgelaufene Eintraege werden hier geloescht und nicht nur
		// uebersprungen: nach einem Treffer laeuft WordPress nie, das Plugin
		// kaeme also nicht dazu.
		$ttl = isset( $konf['ttl'] ) ? (int) $konf['ttl'] : 0;
		if ( $ttl > 0 && ( time() - $mtime ) > $ttl ) {
			@unlink( $datei );
			return;
		}

		$alter   = max( 0, time() - $mtime );
		$modifi  = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$charset = isset( $konf['charset'] ) && $konf['charset'] ? (string) $konf['charset'] : 'UTF-8';

		header( 'X-Cache: HIT' );
		header( 'X-Cache-Enabled: true' );
		header( 'X-Cache-Handler: advanced-cache.php' );
		header( 'Last-Modified: ' . $modifi );
		header( 'Age: ' . $alter );

		// Bewusst kein max-age: der Browser soll weiter fragen, damit eine
		// Invalidierung bei save_post ankommt.
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false;
		if ( false !== $ims && $ims >= $mtime ) {
			http_response_code( 304 );
			exit;
		}

		// Die Kennzeile am Ende zaehlt zur Laenge. Content-Length auf die
		// blosse Dateigroesse zu setzen und danach noch etwas auszugeben ist
		// der klassische Weg zu einer abgeschnittenen Antwort.
		$kennung = "\n<!-- Served from Lite Page Cache (drop-in) -->";
		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'Content-Length: ' . ( $groesse + strlen( $kennung ) ) );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}

		readfile( $datei );
		echo $kennung;
		exit;
	}
}

lpc_dropin_ausliefern();
