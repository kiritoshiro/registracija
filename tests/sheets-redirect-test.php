<?php
// Stubbed regression test of KIR_Plugin::google_sheets_request() (Apps Script POST -> 302 -> GET).
// No WordPress needed: the WP functions it uses are stubbed below and HTTP is scripted per case.
// Usage: php tests/sheets-redirect-test.php knygos-igarsinimo-registracija.php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
class FakeResp { public $url; function __construct( $u ) { $this->url = $u; } }
class FakeHttp { private $u; function __construct( $u ) { $this->u = $u; } function get_response_object() { return new FakeResp( $this->u ); } }
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$GLOBALS['opts'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array();
foreach ( array( 'add_action', 'add_filter', 'add_shortcode', 'register_activation_hook', 'register_deactivation_hook' ) as $f ) {
    eval( "function $f() { return true; }" );
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) ); }
function current_time() { return '2026-11-06 16:00:00'; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_html_excerpt( $s, $n, $more = '' ) { return mb_strlen( $s ) > $n ? mb_substr( $s, 0, $n ) . $more : $s; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function fake( $method, $url, $args ) {
    $GLOBALS['calls'][] = array( $method, $url, $args );
    $next = array_shift( $GLOBALS['script'] );
    return is_callable( $next ) ? $next( $method, $url, $args ) : $next;
}
function wp_remote_post( $u, $a = array() ) { return fake( 'POST', $u, $a ); }
function wp_remote_get( $u, $a = array() ) { return fake( 'UNSAFE_GET', $u, $a ); }
function wp_safe_remote_get( $u, $a = array() ) { return fake( 'GET', $u, $a ); }
function resp( $code, $body, $headers = array(), $final = null ) {
    $r = array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => $headers );
    if ( $final ) { $r['http_response'] = new FakeHttp( $final ); }
    return $r;
}

require $argv[1];
$plugin = KIR_Plugin::instance();
$m = new ReflectionMethod( 'KIR_Plugin', 'google_sheets_request' );
$m->setAccessible( true );
$EP = 'https://script.google.com/macros/s/AKfyTEST/exec';
$ECHO = 'https://script.googleusercontent.com/macros/echo?user_content_key=abc&lib=x';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; $cond ? $pass++ : $fail++; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; }
function run( $script ) {
    global $m, $plugin;
    $GLOBALS['calls'] = array(); $GLOBALS['script'] = $script; unset( $GLOBALS['opts']['kir_google_sheets_status'] );
    $ok = $m->invoke( $plugin, array( 'action' => 'upsert', 'rows' => array() ) );
    $st = get_option( 'kir_google_sheets_status' );
    return array( $ok, $st ? $st['message'] : '', $GLOBALS['calls'] );
}
$GLOBALS['opts']['kir_google_sheets'] = array( 'enabled' => 1, 'endpoint_url' => $EP, 'secret' => 'sekretas' );

// 1. Google's real flow: POST -> 302 echo URL; GET echo -> JSON ok. A POST to the echo URL would get 400.
$echoServer = function ( $method, $url ) { return 'GET' === $method ? resp( 200, '{"ok":true,"message":"Eilutės išsaugotos."}' ) : resp( 400, '<html>Error 400 (Bad Request)!!1</html>' ); };
list( $ok, $msg, $c ) = run( array( resp( 302, '<html>Moved</html>', array( 'location' => $ECHO ) ), $echoServer ) );
check( 'normal flow returns true', true === $ok );
check( 'status success', 'Google Sheets sinchronizacija atlikta.' === $msg );
check( 'POST sent without auto-redirect', 2 === count( $c ) && 'POST' === $c[0][0] && 0 === $c[0][2]['redirection'] );
check( 'POST carries JSON body with token', false !== strpos( $c[0][2]['body'], '"token":"sekretas"' ) && 'application/json; charset=utf-8' === $c[0][2]['headers']['Content-Type'] );
check( 'second request is GET to the Location', 'GET' === $c[1][0] && $ECHO === $c[1][1] );
check( 'GET has no body and no token', ! isset( $c[1][2]['body'] ) && false === strpos( json_encode( $c[1] ), 'sekretas' ) );

// 2. Wrong token: the script's own JSON error comes through.
list( $ok, $msg ) = run( array( resp( 302, '', array( 'location' => $ECHO ) ), resp( 200, '{"ok":false,"message":"Neteisingas tokenas."}' ) ) );
check( 'wrong token -> false with script message', false === $ok && false !== strpos( $msg, 'Neteisingas tokenas.' ) );

// 3. Relative Location is resolved against the endpoint host.
list( $ok, , $c ) = run( array( resp( 302, '', array( 'location' => '/macros/echo?k=1' ) ), resp( 200, '{"ok":true}' ) ) );
check( 'relative Location -> https://script.google.com/...', true === $ok && 'https://script.google.com/macros/echo?k=1' === $c[1][1] );

// 4. Non-https Location is not followed.
list( $ok, $msg, $c ) = run( array( resp( 302, '', array( 'location' => 'http://evil.example/x' ) ) ) );
check( 'http Location not followed', false === $ok && 1 === count( $c ) && false !== strpos( $msg, 'HTTP 302' ) );

// 5. Protocol-relative Location is not followed.
list( $ok, , $c ) = run( array( resp( 302, '', array( 'location' => '//evil.example/x' ) ) ) );
check( 'protocol-relative Location not followed', false === $ok && 1 === count( $c ) );

// 6. Not shared with "Anyone": redirect to Google sign-in -> diagnostic hint.
list( $ok, $msg ) = run( array( resp( 302, '', array( 'location' => 'https://accounts.google.com/ServiceLogin?continue=x' ) ), resp( 200, '<html><title>Sign in</title>Sign in - Google Accounts</html>', array(), 'https://accounts.google.com/v3/signin' ) ) );
check( 'sign-in page -> false with Anyone hint', false === $ok && false !== strpos( $msg, 'accounts.google.com' ) && false !== strpos( $msg, '„Anyone“' ) );

// 7. Endpoint answering 200 JSON directly (no redirect) still works with one request.
list( $ok, , $c ) = run( array( resp( 200, '{"ok":true}' ) ) );
check( 'direct 200 JSON -> true, single request', true === $ok && 1 === count( $c ) );

// 8. Network error on POST.
list( $ok, $msg ) = run( array( new WP_Error( 'x', 'cURL error 28: timed out' ) ) );
check( 'POST WP_Error reported', false === $ok && false !== strpos( $msg, 'cURL error 28' ) );

// 9. Network error on the GET hop.
list( $ok, $msg ) = run( array( resp( 302, '', array( 'location' => $ECHO ) ), new WP_Error( 'x', 'cURL error 6: could not resolve' ) ) );
check( 'GET WP_Error reported', false === $ok && false !== strpos( $msg, 'cURL error 6' ) );

// 10. Location given as an array (repeated header): last one used.
list( $ok, , $c ) = run( array( resp( 302, '', array( 'location' => array( 'https://a.example/1', $ECHO ) ) ), resp( 200, '{"ok":true}' ) ) );
check( 'array Location -> last value', true === $ok && $ECHO === $c[1][1] );

// 11. 307 is not converted to GET (method must be kept; reported instead).
list( $ok, $msg, $c ) = run( array( resp( 307, '', array( 'location' => $ECHO ) ) ) );
check( '307 not followed as GET', false === $ok && 1 === count( $c ) && false !== strpos( $msg, 'HTTP 307' ) );


// 12. https Location on a non-Google host is not followed (SSRF guard).
list( $ok, $msg, $c ) = run( array( resp( 302, '', array( 'location' => 'https://intranet.local/admin' ) ) ) );
check( 'non-Google https Location not followed', false === $ok && 1 === count( $c ) && false !== strpos( $msg, 'HTTP 302' ) );

// 13. Look-alike host is not followed.
list( $ok, , $c ) = run( array( resp( 302, '', array( 'location' => 'https://script.googleusercontent.com.evil.example/x' ) ) ) );
check( 'look-alike Google host not followed', false === $ok && 1 === count( $c ) );

// 14. The redirect GET goes through the SSRF-safe client, never plain wp_remote_get.
list( , , $c ) = run( array( resp( 302, '', array( 'location' => $ECHO ) ), resp( 200, '{"ok":true}' ) ) );
check( 'GET uses wp_safe_remote_get', 'GET' === $c[1][0] );

// 15. A reply that echoes the token does not put it into the stored status.
list( $ok, $msg ) = run( array( resp( 302, '', array( 'location' => $ECHO ) ), resp( 500, '<html>Bad request {"token":"sekretas","action":"upsert"} sekretas</html>' ) ) );
check( 'token redacted from stored excerpt', false === $ok && false === strpos( $msg, 'sekretas' ) && false !== strpos( $msg, '[tokenas]' ) );

// 16. 308 is not converted to GET either.
list( $ok, , $c ) = run( array( resp( 308, '', array( 'location' => $ECHO ) ) ) );
check( '308 not followed as GET', false === $ok && 1 === count( $c ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
