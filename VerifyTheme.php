<?php
/**
 * VerifyTheme - Envato-style license verification and admin UI.
 *
 * - Envato_License_Manager: API client, caching, option persistence, DI-friendly.
 * - VerifyTheme_Admin: Admin page + secure AJAX activation/deactivation with rate limiting.
 *
 * Configure via constants (recommended to define externally, not committed):
 *   THEME_LICENSE_ITEM_ID   (int|string) REQUIRED
 *   THEME_LICENSE_API_URL   (string)      REQUIRED
 *   THEME_LICENSE_API_KEY   (string)      REQUIRED (treat as secret)
 *
 * Usage:
 *   // in functions.php (or plugin bootstrap)
 *   require_once __DIR__ . '/install/license-manager/VerifyTheme.php';
 *   VerifyTheme_Admin::init(); // registers hooks and admin page
 *
 * Tests may instantiate Envato_License_Manager with 'http_client' override.
 *
 * @package UtenzoTheme\License
 * @see Envato_License_Manager for low-level API usage
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Safe-guard for direct access
    exit;
}
define("ITEM_ID","58310150");
define('API_URL', 'https://beplusapi.kinsta.cloud/');
define('API_SECRET_KEY', 'a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2');
/* =========================
 * Envato_License_Manager
 * ========================= */
if ( ! class_exists( 'Envato_License_Manager' ) ) {

  class Envato_License_Manager {

      protected string $item_id;
      protected string $api_url;
      protected string $api_key;
      protected string $option_name;
      protected $http_client;
      protected int $timeout = 30;
      protected ?array $in_memory_state = null;

      /**
       * @param array $config  Keys: item_id, api_url, api_key, option (optional), http_client (callable optional)
       * @throws InvalidArgumentException
       */
      public function __construct( array $config ) {
          if ( empty( $config['item_id'] ) || empty( $config['api_url'] ) || empty( $config['api_key'] ) ) {
              throw new InvalidArgumentException( 'item_id, api_url and api_key are required.' );
          }

          $this->item_id     = (string) $config['item_id'];
          $this->api_url     = rtrim( (string) $config['api_url'], '/' );
          $this->api_key     = (string) $config['api_key'];
          $this->option_name = ! empty( $config['option'] ) ? (string) $config['option'] : '_verifytheme_settings';
          $this->http_client = isset( $config['http_client'] ) && is_callable( $config['http_client'] ) ? $config['http_client'] : null;
      }

      /**
       * Verify and return decoded purchase information or WP_Error.
       *
       * Caches result in transient for 1 hour.
       *
       * @param string $purchase_code
       * @return object|array|WP_Error
       */
      public function get_purchase_info( string $purchase_code ) {
          $purchase_code = $this->sanitize_code( $purchase_code );
          if ( $purchase_code === '' ) {
              return new WP_Error( 'invalid_code', 'Empty purchase code.' );
          }

          $transient = 'envato_purchase_' . md5( $this->item_id . '|' . $purchase_code );
          if ( function_exists( 'get_transient' ) ) {
              $cached = get_transient( $transient );
              if ( $cached ) {
                  return $cached;
              }
          }

          $endpoint = sprintf( '%s/api/v2/verify-purchase-code/%s', $this->api_url, rawurlencode( $purchase_code ) );
          $resp = $this->http_request( 'GET', $endpoint, [
              'headers' => [ 'X-API-KEY' => $this->api_key ],
              'timeout' => $this->timeout,
          ] );

          if ( is_wp_error( $resp ) ) {
              return $resp;
          }

          $body = $this->extract_body( $resp );
          $decoded = json_decode( $body );

          if ( null === $decoded ) {
              return new WP_Error( 'invalid_response', 'Could not decode API response.' );
          }

          if ( function_exists( 'set_transient' ) ) {
              set_transient( $transient, $decoded, HOUR_IN_SECONDS );
          }
          return $decoded;
      }

      /**
       * Check that purchase code belongs to configured item_id.
       *
       * @param string $purchase_code
       * @return bool
       */
      public function is_purchase_valid( string $purchase_code ) : bool {
          $info = $this->get_purchase_info( $purchase_code );
          if ( is_wp_error( $info ) ) {
              return false;
          }

          $found = null;
          if ( is_object( $info ) ) {
              if ( isset( $info->data->item->id ) ) {
                  $found = (string) $info->data->item->id;
              } elseif ( isset( $info->item->id ) ) {
                  $found = (string) $info->item->id;
              }
          } elseif ( is_array( $info ) ) {
              if ( isset( $info['data']['item']['id'] ) ) {
                  $found = (string) $info['data']['item']['id'];
              } elseif ( isset( $info['item']['id'] ) ) {
                  $found = (string) $info['item']['id'];
              }
          }
          return $found === $this->item_id;
      }

      /**
       * Activate license for current domain (register domain and persist state).
       *
       * @param string $purchase_code
       * @return bool|WP_Error
       */
      public function activate( string $purchase_code ) {
          $purchase_code = $this->sanitize_code( $purchase_code );
          if ( $purchase_code === '' ) {
              return new WP_Error( 'invalid_code', 'Empty purchase code.' );
          }

          if ( ! $this->is_purchase_valid( $purchase_code ) ) {
              return new WP_Error( 'invalid_purchase', 'Purchase code is not valid for this item.' );
          }

          $domain = $this->get_current_domain();
          if ( $this->is_localhost( $domain ) ) {
              $this->persist_license_state( [
                  'purchase_code' => $purchase_code,
                  'domain' => $domain,
                  'activated_at' => time(),
              ] );
              return true;
          }

          $reg = $this->register_domain( $purchase_code, $domain );
          if ( is_wp_error( $reg ) ) {
              return $reg;
          }

          $this->persist_license_state( [
              'purchase_code' => $purchase_code,
              'domain' => $domain,
              'activated_at' => time(),
          ] );

          return true;
      }

      /**
       * Deactivate license for this installation (unregister remote domain if applicable).
       *
       * @return bool|WP_Error
       */
      public function deactivate() {
          $state = $this->get_stored_state();
          if ( empty( $state ) || empty( $state['purchase_code'] ) ) {
              $this->delete_stored_state();
              return true;
          }

          $purchase_code = $state['purchase_code'];
          $domain = $this->get_current_domain();

          if ( ! $this->is_localhost( $domain ) ) {
              $unreg = $this->unregister_domain( $purchase_code );
              if ( is_wp_error( $unreg ) ) {
                  // remove local state anyway to allow reactivation
                  $this->delete_stored_state();
                  return $unreg;
              }
          }

          $this->delete_stored_state();
          return true;
      }

      /**
       * Return connected domains info or WP_Error.
       *
       * @param string $purchase_code
       * @return mixed
       */
      public function get_connected_domains( string $purchase_code ) {
          $purchase_code = $this->sanitize_code( $purchase_code );
          if ( $purchase_code === '' ) {
              return new WP_Error( 'invalid_code', 'Empty purchase code.' );
          }

          $endpoint = sprintf( '%s/api/v2/registered-by-purchase-code/%s', $this->api_url, rawurlencode( $purchase_code ) );
          $resp = $this->http_request( 'GET', $endpoint, [
              'headers' => [ 'X-API-KEY' => $this->api_key ],
              'timeout' => $this->timeout,
          ] );

          if ( is_wp_error( $resp ) ) {
              return $resp;
          }

          $body = $this->extract_body( $resp );
          $decoded = json_decode( $body );
          if ( null === $decoded ) {
              return new WP_Error( 'invalid_response', 'Could not decode API response.' );
          }
          return $decoded;
      }

      /* --------- Remote register/unregister --------- */

      protected function register_domain( string $purchase_code, string $domain ) {
          $endpoint = sprintf( '%s/api/v2/register-domain-by-purchase-code', $this->api_url );
          $resp = $this->http_request( 'POST', $endpoint, [
              'headers' => [ 'X-API-KEY' => $this->api_key ],
              'body' => [ 'purchase_code' => $purchase_code, 'domain' => $domain ],
              'timeout' => $this->timeout,
          ] );
          if ( is_wp_error( $resp ) ) {
              return $resp;
          }
          // best-effort: accept success flag or truthy result; otherwise assume ok
          $body = $this->extract_body( $resp );
          $decoded = json_decode( $body );
          if ( is_object( $decoded ) && ( (isset($decoded->success) && $decoded->success) || (isset($decoded->result) && $decoded->result) ) ) {
              return true;
          }
          return true;
      }

      protected function unregister_domain( string $purchase_code ) {
          $endpoint = sprintf( '%s/api/v2/unregister-domain-by-purchase-code', $this->api_url );
          $resp = $this->http_request( 'POST', $endpoint, [
              'headers' => [ 'X-API-KEY' => $this->api_key ],
              'body' => [ 'purchase_code' => $purchase_code ],
              'timeout' => $this->timeout,
          ] );
          if ( is_wp_error( $resp ) ) {
              return $resp;
          }
          return true;
      }

      /* --------- Helpers & persistence --------- */

      protected function http_request( string $method, string $endpoint, array $args = [] ) {
          $method = strtoupper( $method );
          if ( $this->http_client ) {
              return call_user_func( $this->http_client, $method, $endpoint, $args );
          }

          if ( function_exists( 'wp_remote_request' ) ) {
              $args = wp_parse_args( $args, [ 'blocking' => true ] );
              $args['headers'] = $args['headers'] ?? [];
              return wp_remote_request( $endpoint, array_merge( $args, [ 'method' => $method ] ) );
          }

          return new WP_Error( 'no_http', 'No HTTP transport available.' );
      }

      protected function extract_body( $response ) : string {
          if ( is_array( $response ) && isset( $response['body'] ) ) {
              return (string) $response['body'];
          }
          if ( is_string( $response ) ) {
              return $response;
          }
          return '';
      }

      protected function persist_license_state( array $state ) : void {
          if ( function_exists( 'update_option' ) ) {
              update_option( $this->option_name, wp_json_encode( $state ), false );
          } else {
              $this->in_memory_state = $state;
          }
      }

      protected function get_stored_state() : ?array {
          if ( function_exists( 'get_option' ) ) {
              $val = get_option( $this->option_name );
              if ( empty( $val ) ) {
                  return null;
              }
              $decoded = json_decode( $val, true );
              return is_array( $decoded ) ? $decoded : null;
          }
          return $this->in_memory_state;
      }

      protected function delete_stored_state() : void {
          if ( function_exists( 'delete_option' ) ) {
              delete_option( $this->option_name );
          } else {
              $this->in_memory_state = null;
          }
      }

      protected function get_current_domain() : string {
          if ( function_exists( 'home_url' ) ) {
              $url = home_url();
          } else {
              $url = ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
              if ( strpos( $url, 'http' ) !== 0 ) {
                  $url = 'http://' . $url;
              }
          }
          if ( function_exists( 'wp_parse_url' ) ) {
              $parts = wp_parse_url( $url );
              return $parts['host'] ?? ( parse_url( $url, PHP_URL_HOST ) ?: $url );
          }
          return parse_url( $url, PHP_URL_HOST ) ?: $url;
      }

      protected function is_localhost( string $host ) : bool {
          return false;
          $host = strtolower( trim( $host ) );
          if ( $host === '' || $host === 'localhost' ) {
              return true;
          }
          $locals = [ '.local', '.test', '.example', '.localhost', '.dev' ];
          foreach ( $locals as $tld ) {
              if ( substr( $host, -strlen( $tld ) ) === $tld ) {
                  return true;
              }
          }
          return false;
      }

      protected function sanitize_code( string $code ) : string {
          if ( function_exists( 'sanitize_text_field' ) ) {
              return sanitize_text_field( $code );
          }
          return trim( $code );
      }

      /**
       * Check if license is currently activated.
       *
       * @return bool
       */
      public function is_activated() : bool {
          $state = $this->get_stored_state();
          return ! empty( $state ) && ! empty( $state['purchase_code'] );
      }

      /**
       * Get stored license state
       *
       * @return array|null
       */
      public function get_license_state() : ?array {
          return $this->get_stored_state();
      }
  }

} // end Envato_License_Manager


/* =========================
 * VerifyTheme_Admin
 * ========================= */

if ( ! class_exists( 'VerifyTheme_Admin' ) ) {

  class VerifyTheme_Admin {

      public static function init() : void {
          add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
          add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
          add_action( 'wp_ajax_verifytheme_activate', [ __CLASS__, 'ajax_activate' ] );
          add_action( 'wp_ajax_verifytheme_deactivate', [ __CLASS__, 'ajax_deactivate' ] );
      }

      protected static function get_manager() : Envato_License_Manager {
          // prefer constants defined elsewhere; else throw to surface configuration error
          $item_id = defined( 'THEME_LICENSE_ITEM_ID' ) ? THEME_LICENSE_ITEM_ID : ( defined( 'ITEM_ID' ) ? ITEM_ID : '' );
          $api_url = defined( 'THEME_LICENSE_API_URL' ) ? THEME_LICENSE_API_URL : ( defined( 'API_URL' ) ? API_URL : '' );
          $api_key = defined( 'THEME_LICENSE_API_KEY' ) ? THEME_LICENSE_API_KEY : ( defined( 'API_SECRET_KEY' ) ? API_SECRET_KEY : '' );

          if ( empty( $item_id ) || empty( $api_url ) || empty( $api_key ) ) {
              wp_die( 'License manager not configured. Please define THEME_LICENSE_ITEM_ID, THEME_LICENSE_API_URL and THEME_LICENSE_API_KEY.' );
          }

          return new Envato_License_Manager( [
              'item_id' => $item_id,
              'api_url' => $api_url,
              'api_key' => $api_key,
          ] );
      }

      public static function add_admin_page() : void {
          add_submenu_page(
              'themes.php',
              'Envato Settings',
              'Envato Settings',
              'manage_options',
              'verifytheme_settings',
              [ __CLASS__, 'render_admin_page' ]
          );
          
      }

      public static function enqueue_admin_assets( $hook ) : void {
          if ( !($hook === 'appearance_page_verifytheme_settings' || $hook === 'appearance_page_import-demo-page') ) {
              return;
          }
          $setting_page = admin_url('options-general.php?page=verifytheme_settings');
          wp_enqueue_style( 'verifytheme-admin-style', get_template_directory_uri() . '/install/license-manager/verifytheme.css', [], null );
          wp_enqueue_script( 'verifytheme-admin', get_template_directory_uri() . '/install/license-manager/verifytheme.js', [ 'jquery' ], null, true );

          wp_localize_script(
              'verifytheme-admin',
              'verifytheme',
              [
                  'ajax_url' => admin_url( 'admin-ajax.php' ),
                  'setting_page' => admin_url( 'themes.php?page=verifytheme_settings' ),
                  'nonce'    => wp_create_nonce( 'verifytheme_action' ),
                  'strings'  => [
                      'confirm_import'            => __( 'Proceed?', 'alone' ),
                      'deactivate_confirm'        => __( 'Are you sure you want to deregister this license on this site?', 'alone' ),
                      'please_enter_purchase_code'=> __( 'Please enter a purchase code.', 'alone' ),
                      'ajax_error'                => __( 'AJAX error. Please try again.', 'alone' ),
                      'verifying'                 => __( 'Verifying...', 'alone' ),
                      'processing'                => __( 'Processing...', 'alone' ),
                      'license_activated'         => __( 'License activated.', 'alone' ),
                      'license_deactivated'       => __( 'License deactivated.', 'alone' ),
                      'activation_failed'         => __( 'Activation failed.', 'alone' ),
                      'deactivation_failed'       => __( 'Deactivation failed.', 'alone' ),
                      'too_many_attempts'         => __( 'Too many attempts. Try again later.', 'alone' ),
                      'unauthorized'              => __( 'Unauthorized', 'alone' ),
                  ],
              ]
          );
      }

      /**
       * Render settings page. The actual activation/deactivation uses AJAX.
       */
      public static function render_admin_page() : void {
          if ( ! current_user_can( 'manage_options' ) ) {
              return;
          }

          $mgr = self::get_manager();

          $option_key = ( new ReflectionClass( $mgr ) )->getProperty( 'option_name' );
          $option_key->setAccessible(true);
          $option = $option_key->getValue( $mgr );

          $stored = function_exists( 'get_option' ) ? get_option( $option ) : null;
          $stored = $stored ? json_decode( $stored, true ) : null;
          $purchase_code = $stored['purchase_code'] ?? '';
          $domain = $stored['domain'] ?? '';
          $is_activated = $mgr->is_activated();
          ?>
          <div class="wrap">
              <h1><?php esc_html_e( 'Envato Settings', 'alone' ); ?></h1>
              <p><?php esc_html_e( 'Enter your Envato purchase code to activate the theme on this domain.', 'alone' ); ?></p>

              <?php if ( $is_activated ) : ?>
                  <div class="notice notice-success"><p>
                      <?php printf( esc_html__( 'License activated on: %s', 'alone' ), esc_html( $domain ) ); ?>
                  </p></div>
              <?php endif; ?>

              <table class="form-table">
                  <tr>
                      <th scope="row"><label for="verify_purchase_code"><?php esc_html_e( 'Purchase code', 'alone' ); ?></label></th>
                      <td>
                          <input id="verify_purchase_code" class="regular-text" type="text" value="<?php echo esc_attr( $purchase_code ); ?>" <?php echo $is_activated ? 'disabled' : ''; ?> />
                          <p class="description"><?php esc_html_e( 'Enter purchase code and click Activate.', 'alone' ); ?></p>
                      </td>
                  </tr>
              </table>

              <p>
                  <button id="verify_activate" class="button button-primary" <?php echo $is_activated ? 'disabled' : ''; ?>>
                      <?php esc_html_e( 'Activate', 'alone' ); ?>
                  </button>
                  <button id="verify_deactivate" class="button" <?php echo ! $is_activated ? 'disabled' : ''; ?>>
                      <?php esc_html_e( 'Deactivate', 'alone' ); ?>
                  </button>
              </p>

              <div id="verifytheme_message" aria-live="polite"></div>
          </div>
          <?php
      }

      /**
       * AJAX handler: activate license.
       * Security:
       *  - Users must have manage_options
       *  - Nonce checked
       *  - Per-user transient rate limiting (5 attempts per hour)
       */
      public static function ajax_activate() {
          if ( ! self::check_ajax_security() ) {
              wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
          }

          $purchase_code = isset( $_POST['purchase_code'] ) ? sanitize_text_field( wp_unslash( $_POST['purchase_code'] ) ) : '';
          if ( $purchase_code === '' ) {
              wp_send_json_error( [ 'message' => 'Empty purchase code.' ] );
          }

          // rate limit attempts per user
          $user_key = self::get_rate_key();
          if ( ! self::rate_limit_ok( $user_key, 5, HOUR_IN_SECONDS ) ) {
              wp_send_json_error( [ 'message' => 'Too many attempts. Try again later.' ], 429 );
          }

          $mgr = self::get_manager();
          $result = $mgr->activate( $purchase_code );
          if ( is_wp_error( $result ) ) {
              wp_send_json_error( [ 'message' => $result->get_error_message() ] );
          }

          wp_send_json_success( [ 'message' => 'License activated.' ] );
      }

      /**
       * AJAX handler: deactivate license.
       */
      public static function ajax_deactivate() {
          if ( ! self::check_ajax_security() ) {
              wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
          }

          $mgr = self::get_manager();
          $result = $mgr->deactivate();
          if ( is_wp_error( $result ) ) {
              wp_send_json_error( [ 'message' => $result->get_error_message() ] );
          }
          wp_send_json_success( [ 'message' => 'License deactivated.' ] );
      }

      /* ---------------- Security & helpers ---------------- */

      protected static function check_ajax_security() : bool {
          if ( ! current_user_can( 'manage_options' ) ) {
              return false;
          }
          if ( empty( $_REQUEST['nonce'] ) ) {
              return false;
          }
          if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'verifytheme_action' ) ) {
              return false;
          }
          return true;
      }

      /**
       * Get a unique per-user rate key (uses user ID if available, else IP-based).
       */
      protected static function get_rate_key() : string {
          $uid = get_current_user_id();
          if ( $uid ) {
              return 'verifytheme_rate_user_' . $uid;
          }
          $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
          return 'verifytheme_rate_ip_' . md5( $ip );
      }

      /**
       * Simple rate limiter using transients: allow $max attempts per $period seconds.
       *
       * @param string $key
       * @param int    $max
       * @param int    $period
       * @return bool true if allowed
       */
      protected static function rate_limit_ok( string $key, int $max, int $period ) : bool {
          if ( ! function_exists( 'get_transient' ) ) {
              return true;
          }
          $data = get_transient( $key );
          if ( ! is_array( $data ) ) {
              $data = [ 'count' => 0, 'first' => time() ];
          }

          // reset if period expired
          if ( time() - $data['first'] > $period ) {
              $data = [ 'count' => 0, 'first' => time() ];
          }

          if ( $data['count'] >= $max ) {
              // still set transient so expiry continues
              set_transient( $key, $data, $period - ( time() - $data['first'] ) );
              return false;
          }

          $data['count']++;
          set_transient( $key, $data, $period - ( time() - $data['first'] ) );
          return true;
      }
  }

} // end VerifyTheme_Admin