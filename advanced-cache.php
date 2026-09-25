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
 * der Cache waere dann still wirkungslos statt kaputt. Wer eine der beiden
 * Seiten aendert, aendert die andere mit.
 *
 * WAS HIER NICHT GESCHIEHT: geschrieben wird nichts. Ein fehlender Eintrag
 * fuehrt zurueck in den normalen Ablauf, und das Plugin legt ihn wie bisher
 * am Ende der Anfrage an.
 *
 * Die Kennzeile darunter macht die Datei wiedererkennbar: das Plugin fasst
 * wp-content/advanced-cache.php nur an, wenn sie dort steht. Ein Drop-in
 * eines anderen Cache-Plugins bleibt damit unangetastet.
 *
 * @package lite-page-cache
 */

// LPC-DROPIN 1.1.0

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( 'lpc_dropin_ausliefern' ) ) {

	/**
	 * Einen abgelegten Eintrag ausliefern, falls einer passt.
	 *
	 * Kehrt in jedem anderen Fall einfach zurueck; WordPress laeuft dann
	 * normal weiter.
	 */
	function lpc_dropin_ausliefern() {

		// Kommandozeile, Cron und Installationsroutine haben mit
		// ausgelieferten Seiten nichts zu tun.
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

		// ---- Nur eine schlichte GET-Anfrage --------------------------
		// Gleiche Bedingung wie im Plugin: leeres $_GET, Methode GET.
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] || ! empty( $_GET ) ) {
			return;
		}

		// ---- Wer angemeldet ist, sieht etwas anderes ------------------
		// Das Plugin fragt is_user_logged_in(); hier gibt es die Funktion
		// noch nicht, und deshalb wird das Merkmal geprueft, aus dem
		// WordPress sie ableitet - das Anmeldecookie. Dazu die beiden
		// Cookies, die eine Seite ebenfalls persoenlich machen: das
		// Beitragspasswort und die gemerkten Kommentardaten.
		// LIEBER EINMAL ZU OFT AUSSTEIGEN: ein unbekanntes Cookie kostet
		// hier eine langsame Antwort, eine falsche Auslieferung kostet
		// die Sitzung eines anderen.
		if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $name ) {
				if ( 0 === strpos( $name, 'wordpress_logged_in_' )
					|| 0 === strpos( $name, 'wp-postpass_' )
					|| 0 === strpos( $name, 'comment_author_' ) ) {
					return;
				}
			}
		}

		// ---- Nur unter dem eigenen Namen ------------------------------
		$angefragt = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		$angefragt = preg_replace( '/:\d+$/', '', $angefragt );
		if ( '' === $angefragt || $angefragt !== $konf['host'] ) {
			return;
		}

		// ---- Schema genau so bestimmen wie is_ssl() -------------------
		// Nachgebaut statt sinngemaess uebernommen: is_ssl() prueft den
		// Port NUR, wenn $_SERVER['HTTPS'] ueberhaupt nicht gesetzt ist.
		// Ein gesetztes 'off' bedeutet dort also http, auch auf Port 443.
		// Eine Abweichung an dieser Stelle erzeugt einen zweiten
		// Schluessel fuer dieselbe Seite, und der Cache traefe nie.
		$https = false;
		if ( isset( $_SERVER['HTTPS'] ) ) {
			$h = strtolower( (string) $_SERVER['HTTPS'] );
			if ( 'on' === $h || '1' === (string) $_SERVER['HTTPS'] ) {
				$https = true;
			}
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) {
			$https = true;
		}

		// ---- Pfad ohne Abfrageteil ------------------------------------
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

		// Dieselbe Untergrenze wie im Plugin. Was kleiner ist, ist keine
		// Seite, sondern der Rest eines Fehlschlags.
		$min = isset( $konf['min_bytes'] ) ? (int) $konf['min_bytes'] : 300;
		if ( $groesse < $min ) {
			return;
		}

		// ---- Verfallszeit ---------------------------------------------
		// Abgelaufene Eintraege werden hier geloescht und nicht nur
		// uebersprungen - genau wie im Plugin. Wuerden sie nur
		// uebersprungen, liesse dieser Weg sie liegen, denn nach einem
		// Treffer laeuft WordPress nie, und das Plugin kaeme nicht dazu.
		$ttl = isset( $konf['ttl'] ) ? (int) $konf['ttl'] : 0;
		if ( $ttl > 0 && ( time() - $mtime ) > $ttl ) {
			@unlink( $datei );
			return;
		}

		$alter    = max( 0, time() - $mtime );
		$modifi   = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$charset  = isset( $konf['charset'] ) && $konf['charset'] ? (string) $konf['charset'] : 'UTF-8';

		header( 'X-Cache: HIT' );
		header( 'X-Cache-Enabled: true' );
		// Sagt, dass dieser Treffer VOR WordPress beantwortet wurde. Der
		// Zweig im Plugin sendet die Zeile nicht; damit sind die beiden
		// Wege von aussen unterscheidbar, ohne die Antwortzeit zu messen.
		header( 'X-Cache-Handler: advanced-cache.php' );
		header( 'Last-Modified: ' . $modifi );
		header( 'Age: ' . $alter );

		// ---- Rueckfrage billig beantworten -----------------------------
		// Wer Last-Modified bekommen hat, darf damit wiederkommen. Ein 304
		// spart den ganzen Rumpf. Bewusst kein max-age: der Browser soll
		// weiterhin fragen, damit eine Invalidierung bei save_post
		// ankommt - genau die Ueberlegung wie im Plugin.
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false;
		if ( false !== $ims && $ims >= $mtime ) {
			http_response_code( 304 );
			exit;
		}

		header( 'Content-Type: text/html; charset=' . $charset );
		// Die Kennzeile am Ende zaehlt zur Laenge. Content-Length auf die
		// blosse Dateigroesse zu setzen und danach noch etwas auszugeben
		// ist der klassische Weg zu einer abgeschnittenen Antwort.
		$kennung = "\n<!-- Served from Lite Page Cache (drop-in) -->";
		header( 'Content-Length: ' . ( $groesse + strlen( $kennung ) ) );

		// HEAD beantwortet dieselben Kopfzeilen ohne Rumpf. Der Fall kann
		// hier gar nicht auftreten (oben wird auf GET geprueft) und steht
		// trotzdem da, falls die Bedingung oben je gelockert wird.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}

		readfile( $datei );
		echo $kennung;
		exit;
	}
}

lpc_dropin_ausliefern();
