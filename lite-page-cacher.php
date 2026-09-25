<?php

/**
 * Plugin Name: Lite Page Cache
 * Description: A lightweight, blazingly fast caching plugin for static pages and blog posts.
 * Version: 1.1.0
 * Author: LukasWojcik.com
 * Text Domain: lite-page-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Lite_Page_Cache {
    private $cache_dir;

    /**
     * Wem der Eintrag gehoert, der gerade entsteht: die Beitrags-Kennung
     * bei einer Einzelansicht, sonst 0 (Startseite, Archiv, Kategorie).
     *
     * Gesetzt wird das Feld bei template_redirect, gebraucht wird es im
     * ob-Rueckruf - dazwischen liegt die ganze Seite, und in einer
     * Anfrage laeuft genau eine Abfrage. Es aus dem Rueckruf heraus zu
     * erfragen ginge auch, aber dort ist der Zustand von WordPress
     * bereits im Abbau.
     */
    private $post_id = 0;

    /**
     * Hoechstalter eines Eintrags in Sekunden.
     *
     * WOZU, wenn doch bei jeder Aenderung invalidiert wird: die
     * Invalidierung kennt nur, was WordPress als Haken meldet. Ein
     * geplanter Beitrag, der von aussen veroeffentlicht wird, ein Widget
     * mit fremden Daten, eine Aenderung direkt in der Datenbank - fuer
     * all das gibt es keinen Haken. Die Verfallszeit ist der Riegel
     * dagegen, nicht der Normalweg.
     * EINE STUNDE ist bewusst kurz gewaehlt: die Seite hat einen
     * Veroeffentlichungsplaner (missed-scheduled-posts-publisher), und
     * eine Startseite, die einen neuen Beitrag einen halben Tag lang
     * verschweigt, waere schlimmer als ein paar Neurenderungen mehr.
     * Ueber den Filter lpc_ttl aenderbar, ohne diese Datei anzufassen;
     * 0 schaltet die Verfallszeit ab.
     */
    const TTL_SEKUNDEN = 3600;

    /**
     * Fassung des Drop-ins.
     *
     * Steht als Kennzeile "// LPC-DROPIN <fassung>" auch in
     * advanced-cache.php. Der Vergleich der beiden ist die einzige Art,
     * einen alten Abzug in wp-content zu bemerken: kopiert wird beim
     * Aktivieren, und danach faellt eine Aenderung an der Quelldatei
     * niemandem auf.
     */
    const DROPIN_VERSION = '1.1.0';

    public function __construct() {
        // Define cache directory inside wp-content/cache
        $this->cache_dir = WP_CONTENT_DIR . '/cache/lite-page-cache/';

        // Front-end hooks
        add_action( 'template_redirect', [ $this, 'serve_or_start_cache' ], 0 );

        // Back-end hooks
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_post_lpc_clear_cache', [ $this, 'clear_cache_action' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        
        // Auto-clear cache on post/page updates and deletions.
        // BIS ZUM 28.08.2026 HING HIER delete_cache_files() - also der
        //  Vollflush. Jede gespeicherte Aenderung an irgendeinem Beitrag
        //  warf den ganzen Bestand weg; gemessen schwankte er deshalb
        //  zwischen 0 und 30 Eintraegen, und der Cache war die meiste
        //  Zeit leer. purge_post() nimmt nur den betroffenen Beitrag und
        //  die Listenseiten.
        add_action( 'save_post', [ $this, 'purge_post' ] );
        add_action( 'deleted_post', [ $this, 'purge_post' ] );

        // Auto-clear cache on comment approval
        add_action( 'comment_post', [ $this, 'flush_on_new_comment' ], 10, 2 );
        add_action( 'transition_comment_status', [ $this, 'flush_on_comment_status_change' ], 10, 3 );

        // ---- Drop-in ---------------------------------------------------
        // Die Konfigurationsdatei ist die einzige Bruecke zum Drop-in: dort
        //  steht, was advanced-cache.php nicht selbst ermitteln kann, weil
        //  es vor WordPress laeuft. Sie wird nur neu geschrieben, wenn sich
        //  etwas geaendert hat - der Vergleich kostet einen include.
        add_action( 'init', [ $this, 'dropin_konfig_pflegen' ] );
        add_action( 'admin_post_lpc_dropin', [ $this, 'dropin_action' ] );
    }

    /**
     * Check if page should be cached. If cached, serve it. If not, start output buffering.
     */
    public function serve_or_start_cache() {
        // Do not cache if user is logged in, or it's not a plain GET request.
        if ( is_user_logged_in() || ! empty( $_GET ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            return;
        }

        // ---- Persoenliche Fassungen nie in den Cache ----------------
        // Das Drop-in steigt bei diesen Cookies schon beim Ausliefern aus
        //  (advanced-cache.php), hier fehlte dieselbe Pruefung beim
        //  Speichern. Am 25.09.2026 landete so eine Seite mit vorab
        //  angekreuztem Speicherkaestchen im Cache, und ein eigener, noch
        //  nicht freigegebener Kommentar haette ebenso darin stehen
        //  koennen - fuer alle sichtbar, eine Stunde lang.
        foreach ( array_keys( (array) $_COOKIE ) as $name ) {
            if ( 0 === strpos( $name, 'wordpress_logged_in_' )
                || 0 === strpos( $name, 'wp-postpass_' )
                || 0 === strpos( $name, 'comment_author_' ) ) {
                return;
            }
        }

        // ---- Nur unter dem eigenen Namen ------------------------------
        // Der Schluessel enthielt bis zum 28.08.2026 $_SERVER['HTTP_HOST'],
        //  und den schickt der Browser.
        // WAS DAVON AUF DIESEM SERVER ERREICHBAR WAR, gemessen am
        //  28.08.2026 - und es ist WENIGER, als ich beim Einbau behauptet
        //  hatte: Apache schickt einen unbekannten Namen an den
        //  Vorgabe-vhost (404, 327 B), WordPress sieht ihn nie. Ueber
        //  einen erfundenen Host lassen sich hier also KEINE Eintraege
        //  anlegen. 'lukaswojcik.com' ohne www antwortet mit 301 auf die
        //  www-Fassung, es bleibt genau ein Name uebrig, unter dem
        //  WordPress ueberhaupt antwortet - derselbe, den home_url()
        //  fuehrt. Die Pruefung kostet daher nichts.
        // SIE BLEIBT TROTZDEM, aus zwei Gruenden: die vhost-Lage ist
        //  nicht Sache dieses Plugins und kann sich aendern (ein
        //  ServerAlias genuegt), und ein Schluessel aus fremder Hand ist
        //  eine Zusicherung, die man nicht aus der Ferne pruefen will.
        //  Der wirklich erreichbare Teil des Mangels steckte im Pfad,
        //  siehe get_cache_file_path().
        // DIE PRUEFUNG IST DER WICHTIGE TEIL, nicht das Vereinheitlichen
        //  des Schluessels weiter unten. Wuerde man NUR vereinheitlichen,
        //  waere es schlimmer als vorher: eine unter fremdem Namen
        //  gerenderte Seite landete dann unter dem ECHTEN Schluessel und
        //  wuerde an alle ausgeliefert. Erst beides zusammen taugt.
        // Was hier ausgeschlossen wird, wird NICHT ausgeliefert und NICHT
        //  gecacht - die Seite selbst kommt normal, nur ohne Cache. Das
        //  ist die sichere Richtung: erreichbar bleibt die Seite unter
        //  jedem Namen, schnell nur unter ihrem eigenen.
        if ( ! $this->is_own_host() ) {
            return;
        }

        // ---- Antworten, die kein cachefaehiger 200er sind ------------
        // HIER STAND BIS ZUM 28.08.2026 NICHTS, und der Kommentar oben
        //  behauptete trotzdem eine Pruefung auf "singular post/page".
        //  Die gab es im Code nie - is_singular kam in der ganzen Datei
        //  nicht vor. Gecacht wurde damit alles, was durch
        //  template_redirect laeuft: Feeds, Trackbacks, robots.txt,
        //  Vorschauen - und 404er.
        // DER 404er WAR DER SCHLIMME FALL. Der Serve-Zweig unten macht
        //  nur readfile() + exit und setzt KEINEN Status. Eine einmal
        //  gecachte Fehlerseite kam danach als HTTP 200 zurueck, und
        //  zwar dauerhaft: es gibt keine Verfallszeit, ungueltig wird
        //  der Cache nur ueber save_post, deleted_post, eine
        //  Kommentarfreigabe oder den Knopf im Backend. Fuer eine
        //  Suchmaschine ist das der Unterschied zwischen "Seite weg"
        //  und "Seite da, aber leer" - der zweite Fall wird indexiert.
        // NICHT ENGER ALS NOETIG: Startseite, Archive und Kategorien
        //  bleiben cachefaehig. Der Kommentar von oben haette sie
        //  ausgeschlossen, aber das waere eine Leistungsaenderung, die
        //  niemand verlangt hat - hier fallen nur die Antworten weg,
        //  die als Cache-Eintrag SCHADEN.
        if ( is_404() || is_feed() || is_trackback() || is_robots()
             || is_preview() || is_search() || is_embed() ) {
            return;
        }

        // Ein passwortgeschuetzter Beitrag hat im Cache nichts verloren:
        //  weder sein Klartext (den bekaeme sonst jeder) noch seine
        //  Formularfassung (die bekaeme sonst auch, wer das Passwort
        //  schon eingegeben hat).
        if ( is_singular() && post_password_required() ) {
            return;
        }

        $cache_file = $this->get_cache_file_path();

        // Serve cache if it exists
        if ( file_exists( $cache_file ) ) {
            // ---- Verfallszeit ----------------------------------------
            // Ein abgelaufener Eintrag wird geloescht statt uebersprungen:
            //  sonst bliebe er liegen, bis zufaellig jemand den Beitrag
            //  speichert, und belegte Platz fuer eine Antwort, die nie
            //  wieder ausgeliefert wird. Danach faellt der Ablauf in die
            //  Pufferung unten und die Seite wird neu erzeugt.
            $ttl = (int) apply_filters( 'lpc_ttl', self::TTL_SEKUNDEN );
            $alter = time() - (int) filemtime( $cache_file );
            if ( $ttl > 0 && $alter > $ttl ) {
                @unlink( $cache_file );
            } else {
                $x = file_get_contents($cache_file);
                if(strlen($x) < 300) { unset($cache_file); return; }
                // ---- Kopfzeilen fuer Zwischenspeicher-Erkennung ---------------
                // WordPress Site Health ("Page cache is not detected") fragt die
                //  Startseite dreimal ab und sucht in der Antwort nach einer der
                //  Kopfzeilen aus WP_Site_Health::get_page_cache_headers(): u.a.
                //  x-cache (Wert mit 'hit'), x-cache-enabled ('true'), age (> 0),
                //  last-modified. Ohne eine davon gilt der Cache als nicht
                //  vorhanden - ganz gleich, wie schnell die Antwort kommt.
                // BEWUSST KEIN Cache-Control max-age: das wuerde den BROWSER
                //  cachen lassen, und die Invalidierung bei save_post kaeme dort
                //  nicht an. Age und Last-Modified beschreiben nur den Eintrag.
                header( 'X-Cache: HIT' );
                header( 'X-Cache-Enabled: true' );
                header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $cache_file ) ) . ' GMT' );
                header( 'Age: ' . max( 0, time() - (int) filemtime( $cache_file ) ) );
                readfile( $cache_file );
                echo "\n<!-- Served from Lite Page Cache -->";
                exit;
            }
        }

        // Wem der Eintrag gehoeren wird. is_singular() ist hier belastbar
        //  (die Hauptabfrage steht bei template_redirect fest); im
        //  ob-Rueckruf spaeter waere es eine Wette auf den Abbau.
        //  0 heisst Listenseite - Startseite, Archiv, Kategorie -, und
        //  die trifft jede Aenderung.
        $this->post_id = is_singular() ? (int) get_queried_object_id() : 0;

        // Der Gegenwert zu HIT oben: sagt, dass der Cache laeuft, aber diese
        //  Antwort frisch gerendert wird.
        header( 'X-Cache: MISS' );
        header( 'X-Cache-Enabled: true' );
        // Start buffering to create cache
        ob_start( [ $this, 'save_cache' ] );
    }

    /**
     * Output buffering callback to save the generated HTML.
     */
    public function save_cache( $buffer ) {
        if ( ! file_exists( $this->cache_dir ) ) {
            wp_mkdir_p( $this->cache_dir );
        }

        $cache_file = $this->get_cache_file_path();
        
        // Save the buffer to a file
		if(strlen($buffer) < 300) { return $buffer; }

        // ---- 0. NUR ECHTE 200er --------------------------------------
        // Letzte Instanz. Die Bedingungen in serve_or_start_cache()
        //  laufen bei template_redirect; ein Plugin oder das Theme kann
        //  den Status danach noch setzen (410, 451, eine Weiterleitung).
        //  http_response_code() liefert hier den Stand, mit dem
        //  tatsaechlich geantwortet wurde - die Kopfzeilen sind zu
        //  diesem Zeitpunkt schon raus.
        // Damit ist der Serve-Zweig oben WIEDER RICHTIG, ohne dass er
        //  etwas tun muesste: was im Cache liegt, war ein 200er, also
        //  ist es richtig, es als 200 auszuliefern. Den Status je
        //  Eintrag mitzuschreiben waere die Alternative gewesen - mehr
        //  Format fuer einen Fall, den es dann nicht mehr gibt.
        if ( http_response_code() !== 200 ) { return $buffer; }

        // ---- 1. NUR VOLLSTAENDIGE SEITEN ------------------------------
        // Dieser Rueckruf feuert nicht nur am Skriptende, sondern bei
        //  JEDEM Leeren des Puffers - also auch, wenn der Client die
        //  Verbindung abbricht (ignore_user_abort steht auf Off). Dann
        //  steht ein halbes Dokument im Puffer, und bis zum 28.08.2026
        //  wurde genau das als vollwertiger Eintrag abgelegt. Weil es
        //  keine Verfallszeit gibt, blieb es liegen, bis irgendein
        //  save_post den ganzen Cache leerte - und wurde bis dahin an
        //  jeden ausgeliefert, der die Adresse traf.
        // ZWEI BEDINGUNGEN, und beide muessen sein:
        //  a) Das Dokument ENDET auf </html>. Ein Abbruch tut das nie.
        //     Geprueft wird das Ende und nicht das Vorkommen: ein
        //     Artikel ueber HTML kann die Zeichenfolge im Text fuehren.
        //  b) Der Tracker-Block aus inc/footer-tracker.php ist drin.
        //     Er ist das LETZTE, was footer.php ausgibt - steht er da,
        //     ist die Seite bis zum Ende gerendert worden. Am 28.08.2026
        //     lag ein Abzug im Cache, der vollstaendig aussah und den
        //     ganzen Block nicht hatte: keine CMP, kein Tracking, kein
        //     Chat - eine Seite ohne Einwilligungsdialog, eingefroren.
        // WAS DAS KOSTET, ausdruecklich: Eine Seite, die den Block nicht
        //  einbindet, wird ab jetzt NIE gecacht. Das ist die sichere
        //  Richtung (langsamer statt falsch), aber es ist still. Wer den
        //  Marker in footer-tracker.php umbenennt, schaltet damit das
        //  Caching ab, ohne dass etwas kaputtgeht - dann gehoert diese
        //  Zeile nachgezogen.
        if ( substr( rtrim( $buffer ), -7 ) !== '</html>'
             || strpos( $buffer, 'data-consented="reactive-chat"' ) === false ) {
            return $buffer;
        }

        // ---- 2. ATOMAR SCHREIBEN --------------------------------------
        // file_put_contents() direkt auf die Zieldatei hat ein Fenster, in
        //  dem die Datei existiert und halb geschrieben ist - und der
        //  Serve-Zweig oben prueft nur file_exists(). Zwei gleichzeitige
        //  Anfragen auf dieselbe Adresse schreiben ausserdem beide, ohne
        //  Sperre, ineinander. Ueber eine eigene Datei je Prozess und
        //  rename() ist der Tausch unteilbar: es gibt nur den alten oder
        //  den neuen Stand, nie einen halben.
        // DIE ZUGEHOERIGKEIT GEHOERT IN DIE DATEI. Der Dateiname ist ein
        //  md5 und nicht umkehrbar - ohne diese Zeile laesst sich ein
        //  einzelner Eintrag nicht wiederfinden, und genau deshalb hat
        //  das Plugin bisher immer alles geloescht.
        //  Sie steht in der Fusszeile, die ohnehin geschrieben wird: kein
        //  zweiter Satz Dateien, und wer die .html loescht, loescht die
        //  Angabe mit. Ein Kommentar mit einer oeffentlichen
        //  Beitragsnummer verraet nichts, was die Adresse nicht schon
        //  sagt.
        $inhalt = $buffer . "\n<!-- LukasWojcik.com -->\n<!-- Cached by Lite Page Cache at " . current_time('mysql') . "-->"
                . "\n<!-- LPC post=" . (int) $this->post_id . " -->";
        $tmp    = $cache_file . '.' . getmypid() . '.tmp';
        if ( file_put_contents( $tmp, $inhalt ) === strlen( $inhalt ) ) {
            rename( $tmp, $cache_file );
        } else {
            // Teilschreibung (volle Platte, Abbruch) hinterlaesst nichts.
            //  delete_cache_files() raeumt nur *.html und faende sie nie.
            @unlink( $tmp );
        }
        
        return $buffer;
    }

    /**
     * Generate unique filename based on the requested URL.
     */
    private function get_cache_file_path() {
        // NICHT $_SERVER['HTTP_HOST'], sondern der Name, unter dem sich
        //  WordPress selbst kennt. Zusammen mit is_own_host() oben sind
        //  beide identisch - die Zeile ist der zweite Riegel, falls
        //  jemand die Pruefung einmal lockert.
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        // OHNE ABFRAGETEIL. Gecacht wird ohnehin nur, wenn $_GET leer ist -
        //  aber "/seite?" und "/seite??" haben ein leeres $_GET und je
        //  einen eigenen REQUEST_URI. Das waren beliebig viele Schluessel
        //  fuer eine einzige Seite, und zwar wieder aus fremder Hand.
        $pfad = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $frage = strpos( $pfad, '?' );
        if ( $frage !== false ) {
            $pfad = substr( $pfad, 0, $frage );
        }

        // is_ssl() statt der Handpruefung auf $_SERVER['HTTPS']: hinter
        //  einem Proxy steht dort nichts, und WordPress kennt den Fall.
        $hash = md5( ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $pfad );
        return $this->cache_dir . $hash . '.html';
    }

    /**
     * Kommt die Anfrage unter dem Namen, unter dem sich die Seite kennt?
     *
     * Der Port wird abgeschnitten und beides kleingeschrieben - ein Name
     * ist nach RFC 4343 unabhaengig von der Schreibweise, und ":443" darf
     * den Vergleich nicht entscheiden.
     */
    private function is_own_host() {
        $angefragt = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
        $angefragt = preg_replace( '/:\d+$/', '', $angefragt );
        $eigen     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        return $angefragt !== '' && $eigen !== '' && $angefragt === $eigen;
    }

    /**
     * Add settings page to the Settings menu.
     */
    public function add_admin_menu() {
        add_options_page( 
            'Lite Page Cache', 
            'Lite Page Cache', 
            'manage_options', 
            'lite-page-cache', 
            [ $this, 'settings_page' ] 
        );
    }

    /**
     * Render the admin settings page.
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Lite Page Cache</h1>
            <p>This plugin caches static pages and blog posts to HTML files to improve loading speed.</p>
            
            <div class="card" style="max-width: 600px; margin-top: 20px; padding: 20px;">
                <h2>Cache Management</h2>
                <p>Use the button below to manually flush all cached files.</p>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="lpc_clear_cache">
                    <?php wp_nonce_field( 'lpc_clear_cache_nonce', 'lpc_nonce' ); ?>
                    <?php submit_button( 'Clear All Cache', 'primary' ); ?>
                </form>
            </div>

            <?php $z = $this->dropin_zustand(); ?>
            <div class="card" style="max-width: 600px; margin-top: 20px; padding: 20px;">
                <h2>Drop-in (advanced-cache.php)</h2>
                <p>Ohne Drop-in wird ein Treffer erst bei <code>template_redirect</code> ausgeliefert - also nachdem WordPress, alle Plugins und das Theme geladen sind. Mit Drop-in beantwortet <code>wp-content/advanced-cache.php</code> ihn davor.</p>
                <table class="widefat striped" style="margin-bottom: 12px;">
                    <tbody>
                    <tr><td>Datei in wp-content</td><td><?php echo $z['datei_da'] ? ( $z['unser'] ? 'vorhanden (' . esc_html( $z['fassung'] ? $z['fassung'] : '?' ) . ')' : '<strong>fremdes Drop-in</strong>' ) : 'fehlt'; ?></td></tr>
                    <tr><td>Fassung aktuell</td><td><?php echo $z['aktuell'] ? 'ja' : 'nein (mitgeliefert: ' . esc_html( self::DROPIN_VERSION ) . ')'; ?></td></tr>
                    <tr><td>lpc-config.php</td><td><?php echo $z['konfig_da'] ? 'vorhanden' : 'fehlt'; ?></td></tr>
                    <tr><td>WP_CACHE</td><td><?php echo $z['wp_cache'] ? 'true' : '<strong>nicht gesetzt</strong>'; ?></td></tr>
                    <tr><td>wp-content beschreibbar</td><td><?php echo $z['ziel_ok'] ? 'ja' : 'nein'; ?></td></tr>
                    <tr><td>wp-config.php beschreibbar</td><td><?php echo $z['config_ok'] ? 'ja' : 'nein - die Zeile <code>define( \'WP_CACHE\', true );</code> dann von Hand eintragen'; ?></td></tr>
                    </tbody>
                </table>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block; margin-right: 10px;">
                    <input type="hidden" name="action" value="lpc_dropin">
                    <input type="hidden" name="lpc_was" value="einrichten">
                    <?php wp_nonce_field( 'lpc_dropin_nonce', 'lpc_nonce' ); ?>
                    <?php submit_button( 'Drop-in einrichten / erneuern', 'primary', 'submit', false ); ?>
                </form>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;">
                    <input type="hidden" name="action" value="lpc_dropin">
                    <input type="hidden" name="lpc_was" value="entfernen">
                    <?php wp_nonce_field( 'lpc_dropin_nonce', 'lpc_nonce' ); ?>
                    <?php submit_button( 'Drop-in zuruecknehmen', 'secondary', 'submit', false ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the clear cache request.
     */
    public function clear_cache_action() {
        if ( ! isset( $_POST['lpc_nonce'] ) || ! wp_verify_nonce( $_POST['lpc_nonce'], 'lpc_clear_cache_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized request.' );
        }

        $this->delete_cache_files();

        wp_redirect( add_query_arg( [ 'page' => 'lite-page-cache', 'cleared' => 'true' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Flush cache when a new comment is added and immediately approved.
     */
    public function flush_on_new_comment( $comment_ID, $comment_approved ) {
        // $comment_approved can be 1 (approved), 0 (pending), or 'spam'
        if ( $comment_approved === 1 || $comment_approved === '1' ) {
            // Nur die Seite, an der der Kommentar haengt - plus die
            //  Listenseiten, denn dort steht die Kommentarzahl.
            $c = get_comment( $comment_ID );
            if ( $c ) { $this->delete_entries_for( (int) $c->comment_post_ID ); }
        }
    }

    /**
     * Flush cache when a comment's status changes (e.g., approved from moderation).
     */
    public function flush_on_comment_status_change( $new_status, $old_status, $comment ) {
        if ( $new_status === 'approved' && isset( $comment->comment_post_ID ) ) {
            $this->delete_entries_for( (int) $comment->comment_post_ID );
        }
    }

    /**
     * Einen Beitrag und die Listenseiten verwerfen.
     *
     * Revisionen und Zwischenspeicherungen sind keine Veroeffentlichung -
     * WordPress feuert save_post fuer beide, und ohne diese Zeile wuerde
     * der Cache waehrend des Tippens im Editor im Sekundentakt geleert.
     */
    public function purge_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return; }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
        $this->delete_entries_for( $post_id );
    }

    /**
     * Die Eintraege loeschen, die eine Aenderung an $post_id betrifft.
     *
     * ZWEI GRUPPEN: der Beitrag selbst, und JEDE Listenseite (post=0).
     * Eine Aenderung kann auf der Startseite, im Archiv, in der Kategorie
     * und im Autorenverzeichnis sichtbar werden; welche davon im Cache
     * liegen, weiss nur der Bestand. Sie alle zu verwerfen ist deutlich
     * weniger als der bisherige Vollflush und deckt die Faelle ab, die
     * sich nicht aufzaehlen lassen (Seite 2 eines Archivs zum Beispiel).
     *
     * EIN EINTRAG OHNE ANGABE GILT ALS LISTENSEITE. Das sind die
     * Eintraege aus der Zeit vor dieser Aenderung; sie verhalten sich
     * damit wie bisher (werden also verworfen) und verschwinden von
     * selbst, statt ewig liegenzubleiben.
     */
    private function delete_entries_for( $post_id ) {
        $dateien = glob( $this->cache_dir . '*.html' );
        if ( ! $dateien ) { return; }
        foreach ( $dateien as $datei ) {
            $gehoert = $this->entry_post_id( $datei );
            if ( $gehoert === 0 || $gehoert === (int) $post_id ) {
                @unlink( $datei );
            }
        }
    }

    /**
     * Wem ein abgelegter Eintrag gehoert - aus seiner Fusszeile.
     *
     * Gelesen werden die letzten 512 Byte und nicht die ganze Datei: bei
     * 160 kB je Eintrag ist der Unterschied zwischen "ein Block" und
     * "der ganze Bestand" genau der zwischen unmerklich und spuerbar,
     * und die Fusszeile steht am Ende.
     */
    private function entry_post_id( $datei ) {
        $groesse = @filesize( $datei );
        if ( ! $groesse ) { return 0; }
        $f = @fopen( $datei, 'rb' );
        if ( ! $f ) { return 0; }
        // Kleiner als das Fenster: von vorn lesen, sonst faellt fseek
        //  hinter den Dateianfang und liest gar nichts.
        fseek( $f, $groesse > 512 ? $groesse - 512 : 0, SEEK_SET );
        $ende = (string) fread( $f, 512 );
        fclose( $f );
        if ( preg_match( '/<!-- LPC post=(\d+) -->/', $ende, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Delete all generated HTML cache files.
     */
    public function delete_cache_files() {
        if ( file_exists( $this->cache_dir ) ) {
            $files = glob( $this->cache_dir . '*.html' );
            if ( $files ) {
                array_map( 'unlink', $files );
            }
        }
    }


    // =================================================================
    //  Drop-in: wp-content/advanced-cache.php
    // =================================================================

    /**
     * Wohin das Drop-in gehoert. WordPress bindet ausschliesslich diesen
     * Pfad ein, und nur dann, wenn WP_CACHE wahr ist.
     */
    private function dropin_ziel() {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    /** Die mitgelieferte Fassung im Plugin-Ordner. */
    private function dropin_quelle() {
        return plugin_dir_path( __FILE__ ) . 'advanced-cache.php';
    }

    /** Die Bruecke zwischen Plugin und Drop-in. */
    private function konfig_datei() {
        return $this->cache_dir . 'lpc-config.php';
    }

    /**
     * Stammt die Datei in wp-content von diesem Plugin?
     *
     * Ein fremdes Drop-in wird NICHT ueberschrieben und NICHT geloescht.
     * Zwei Cache-Plugins gleichzeitig sind ein Fehler des Betreibers, aber
     * fremde Dateien wegzuraeumen ist ein Fehler dieses Plugins.
     */
    private function dropin_ist_unser( $pfad ) {
        if ( ! is_readable( $pfad ) ) { return false; }
        $kopf = (string) @file_get_contents( $pfad, false, null, 0, 2048 );
        return strpos( $kopf, 'LPC-DROPIN' ) !== false;
    }

    /** Fassung, die in wp-content liegt - oder leer. */
    private function dropin_fassung( $pfad ) {
        if ( ! is_readable( $pfad ) ) { return ''; }
        $kopf = (string) @file_get_contents( $pfad, false, null, 0, 2048 );
        return preg_match( '/LPC-DROPIN\s+([0-9.]+)/', $kopf, $m ) ? $m[1] : '';
    }

    /**
     * Die Konfigurationsdatei schreiben, die das Drop-in liest.
     *
     * WAS HIER HINEINGEHOERT: alles, wofuer advanced-cache.php eine
     * WordPress-Funktion braeuchte. Host und Zeichensatz stehen in der
     * Datenbank, die Verfallszeit kann ein Filter aendern - vor WordPress
     * ist nichts davon erreichbar.
     * ATOMAR, aus demselben Grund wie bei den Eintraegen: das Drop-in liest
     * diese Datei bei JEDER Anfrage, und eine halb geschriebene ergaebe
     * einen Parse-Fehler vor dem ersten Byte der Seite.
     */
    public function konfig_schreiben() {
        if ( ! file_exists( $this->cache_dir ) ) {
            wp_mkdir_p( $this->cache_dir );
        }
        $konf = array(
            'aktiv'       => true,
            'host'        => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
            'ttl'         => (int) apply_filters( 'lpc_ttl', self::TTL_SEKUNDEN ),
            'min_bytes'   => 300,
            'charset'     => get_bloginfo( 'charset' ) ? get_bloginfo( 'charset' ) : 'UTF-8',
            'version'     => self::DROPIN_VERSION,
            'geschrieben' => gmdate( 'c' ),
        );
        $inhalt = "<?php\n// Von Lite Page Cache erzeugt, gelesen von advanced-cache.php.\n"
                . "// Aenderungen von Hand werden beim naechsten Lauf ueberschrieben.\n"
                . 'return ' . var_export( $konf, true ) . ";\n";
        $tmp = $this->konfig_datei() . '.' . getmypid() . '.tmp';
        if ( file_put_contents( $tmp, $inhalt ) === strlen( $inhalt ) ) {
            return rename( $tmp, $this->konfig_datei() );
        }
        @unlink( $tmp );
        return false;
    }

    /**
     * Bei jedem Aufruf pruefen, ob die Konfiguration noch stimmt.
     *
     * Neu geschrieben wird nur bei einer Abweichung. Der Fall, um den es
     * geht: jemand aendert die Adresse der Seite oder haengt sich in
     * lpc_ttl - ohne diese Zeile liefe das Drop-in danach mit dem alten
     * Wert weiter, und zwar unbemerkt.
     */
    public function dropin_konfig_pflegen() {
        $datei = $this->konfig_datei();
        if ( is_readable( $datei ) ) {
            $alt = include $datei;
            if ( is_array( $alt )
                && isset( $alt['version'], $alt['host'], $alt['ttl'] )
                && $alt['version'] === self::DROPIN_VERSION
                && $alt['host'] === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
                && (int) $alt['ttl'] === (int) apply_filters( 'lpc_ttl', self::TTL_SEKUNDEN ) ) {
                return;
            }
        }
        $this->konfig_schreiben();
    }

    /**
     * WP_CACHE in wp-config.php setzen.
     *
     * WordPress bindet advanced-cache.php nur ein, wenn diese Konstante
     * wahr ist - die Datei allein bewirkt nichts. Vor der Aenderung
     * entsteht eine Sicherung; scheitert das Schreiben, meldet die
     * Rueckgabe den Grund, und die Oberflaeche nennt die Zeile zum
     * Selbsteintragen.
     */
    private function wp_cache_setzen( $an ) {
        $pfad = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $pfad ) ) {
            $pfad = dirname( ABSPATH ) . '/wp-config.php';
        }
        if ( ! is_readable( $pfad ) ) { return 'nicht_lesbar'; }
        if ( ! is_writable( $pfad ) ) { return 'nicht_schreibbar'; }

        $inhalt = (string) file_get_contents( $pfad );
        $zeile  = "define( 'WP_CACHE', " . ( $an ? 'true' : 'false' ) . " ); // Lite Page Cache";
        $muster = "/^[ \t]*define\s*\(\s*['\"]WP_CACHE['\"].*$/m";

        if ( preg_match( $muster, $inhalt ) ) {
            $neu = preg_replace( $muster, $zeile, $inhalt, 1 );
        } else {
            // Ganz nach oben, denn wp-config.php kann weiter unten bereits
            //  wp-settings.php einbinden - alles danach kaeme zu spaet.
            $neu = preg_replace( '/^<\?php/', "<?php\n" . $zeile, $inhalt, 1 );
        }
        if ( null === $neu ) { return 'muster_fehler'; }
        if ( $neu === $inhalt ) { return 'unveraendert'; }

        @copy( $pfad, $pfad . '.bak-lpc-' . gmdate( 'Ymd-His' ) );
        return false !== file_put_contents( $pfad, $neu ) ? 'ok' : 'schreibfehler';
    }

    /**
     * Drop-in einrichten: Konfiguration, Datei, Konstante.
     * Gibt eine Liste von Beanstandungen zurueck; leer heisst fertig.
     */
    public function dropin_installieren() {
        $meldungen = array();

        if ( ! $this->konfig_schreiben() ) {
            $meldungen[] = 'Die Konfigurationsdatei lpc-config.php liess sich nicht schreiben.';
        }

        $quelle = $this->dropin_quelle();
        $ziel   = $this->dropin_ziel();

        if ( ! is_readable( $quelle ) ) {
            $meldungen[] = 'advanced-cache.php fehlt im Plugin-Ordner.';
            return $meldungen;
        }
        if ( file_exists( $ziel ) && ! $this->dropin_ist_unser( $ziel ) ) {
            $meldungen[] = 'In wp-content liegt ein fremdes advanced-cache.php. Es wurde nicht angefasst.';
            return $meldungen;
        }
        if ( ! @copy( $quelle, $ziel ) ) {
            $meldungen[] = 'Kopieren nach wp-content/advanced-cache.php ist fehlgeschlagen.';
            return $meldungen;
        }

        $stand = $this->wp_cache_setzen( true );
        if ( 'ok' !== $stand && 'unveraendert' !== $stand ) {
            $meldungen[] = 'WP_CACHE liess sich nicht setzen (' . $stand . '). Diese Zeile gehoert oben in wp-config.php: '
                . "define( 'WP_CACHE', true );";
        }
        return $meldungen;
    }

    /**
     * Drop-in zuruecknehmen.
     *
     * Zuerst die Konfiguration abschalten, dann die Datei entfernen: in der
     * Reihenfolge gibt es keinen Augenblick, in dem eine vorhandene Datei
     * ohne gueltige Konfiguration ausliefert.
     */
    public function dropin_entfernen() {
        $meldungen = array();
        $datei = $this->konfig_datei();
        if ( is_readable( $datei ) ) {
            $konf = include $datei;
            if ( is_array( $konf ) ) {
                $konf['aktiv'] = false;
                @file_put_contents( $datei, "<?php\nreturn " . var_export( $konf, true ) . ";\n" );
            }
        }
        $ziel = $this->dropin_ziel();
        if ( file_exists( $ziel ) ) {
            if ( $this->dropin_ist_unser( $ziel ) ) {
                if ( ! @unlink( $ziel ) ) { $meldungen[] = 'wp-content/advanced-cache.php liess sich nicht loeschen.'; }
            } else {
                $meldungen[] = 'wp-content/advanced-cache.php stammt nicht von diesem Plugin und bleibt liegen.';
            }
        }
        $stand = $this->wp_cache_setzen( false );
        if ( 'ok' !== $stand && 'unveraendert' !== $stand ) {
            $meldungen[] = 'WP_CACHE liess sich nicht zuruecksetzen (' . $stand . ').';
        }
        return $meldungen;
    }

    /** Was die Oberflaeche anzeigt. */
    private function dropin_zustand() {
        $ziel = $this->dropin_ziel();
        return array(
            'datei_da'   => file_exists( $ziel ),
            'unser'      => $this->dropin_ist_unser( $ziel ),
            'fassung'    => $this->dropin_fassung( $ziel ),
            'aktuell'    => $this->dropin_fassung( $ziel ) === self::DROPIN_VERSION,
            'konfig_da'  => is_readable( $this->konfig_datei() ),
            'wp_cache'   => defined( 'WP_CACHE' ) && WP_CACHE,
            'ziel_ok'    => is_writable( dirname( $ziel ) ),
            'config_ok'  => is_writable( ABSPATH . 'wp-config.php' ),
        );
    }

    /** Knopf "Einrichten" bzw. "Zuruecknehmen". */
    public function dropin_action() {
        if ( ! isset( $_POST['lpc_nonce'] ) || ! wp_verify_nonce( $_POST['lpc_nonce'], 'lpc_dropin_nonce' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized request.' );
        }
        $was = isset( $_POST['lpc_was'] ) ? sanitize_key( $_POST['lpc_was'] ) : '';
        $meldungen = ( 'entfernen' === $was ) ? $this->dropin_entfernen() : $this->dropin_installieren();
        set_transient( 'lpc_dropin_meldung', $meldungen, 60 );
        wp_redirect( add_query_arg( array( 'page' => 'lite-page-cache', 'dropin' => $was ? $was : 'einrichten' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * Show success notice after clearing cache.
     */
    public function admin_notices() {
        if ( isset( $_GET['cleared'] ) && $_GET['cleared'] === 'true' ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Lite Page Cache:</strong> All cached files have been successfully cleared.</p></div>';
        }

        if ( isset( $_GET['dropin'] ) ) {
            $meldungen = get_transient( 'lpc_dropin_meldung' );
            delete_transient( 'lpc_dropin_meldung' );
            if ( empty( $meldungen ) ) {
                $text = ( 'entfernen' === $_GET['dropin'] ) ? 'Das Drop-in wurde zurueckgenommen.' : 'Das Drop-in ist eingerichtet.';
                echo '<div class="notice notice-success is-dismissible"><p><strong>Lite Page Cache:</strong> ' . esc_html( $text ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Lite Page Cache:</strong></p><ul style="list-style:disc;margin-left:20px;">';
                foreach ( (array) $meldungen as $m ) { echo '<li>' . esc_html( $m ) . '</li>'; }
                echo '</ul></div>';
            }
        }
    }
}

// Initialize the plugin
$lite_page_cache = new Lite_Page_Cache();

// ---- Aktivieren und Deaktivieren -------------------------------------
// Das Drop-in ist eine Datei ausserhalb des Plugin-Ordners und eine
//  Konstante in wp-config.php. Beides muss beim Deaktivieren wieder weg -
//  sonst liefert ein deaktiviertes Plugin weiter aus, und das waere der
//  unangenehmste Zustand von allen.
register_activation_hook( __FILE__, function () use ( $lite_page_cache ) {
    $lite_page_cache->dropin_installieren();
} );
register_deactivation_hook( __FILE__, function () use ( $lite_page_cache ) {
    $lite_page_cache->dropin_entfernen();
    $lite_page_cache->delete_cache_files();
} );