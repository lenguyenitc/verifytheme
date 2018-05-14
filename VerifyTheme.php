<?php
/**
 * Base class for talking to API:s
 *
 * Other API classes may extend this class to take use of the
 * POST and GET request functions.
 */
define("ITEM_ID","20473427"); // Replace it by current item id
define("ENVATO_KEY","d49kexl70or1kr3trir4gka3qoae5eog");
/**
 * Envato Protected API
 *
 * Wrapper class for the Envato marketplace protected API methods specific
 * to the Envato WordPress Toolkit plugin.
 *
 * @package     WordPress
 * @subpackage  Envato WordPress Toolkit
 * @author      Derek Herman <derek@envato.com>
 * @since       1.0
 */
if ( ! class_exists( 'Envato_Protected_API' ) ):
  class Envato_Protected_API {
    /**
     * The buyer's Username
     *
     * @var       string
     *
     * @access    public
     * @since     1.0
     */
    public $user_name;

    /**
     * The buyer's API Key
     *
     * @var       string
     *
     * @access    public
     * @since     1.0
     */
    public $api_key;

    /**
     * The default API URL
     *
     * @var       string
     *
     * @access    private
     * @since     1.0
     */
    protected $public_url = 'https://marketplace.envato.com/api/edge/set.json';

    /**
     * Error messages
     *
     * @var       array
     *
     * @access    public
     * @since     1.0
     */
    public $errors = array( 'errors' => '' );

    /**
     * Class contructor method
     *
     * @param     string      The buyer's Username
     * @param     string      The buyer's API Key can be accessed on the marketplaces via My Account -> My Settings -> API Key
     * @return    void        Sets error messages if any.
     *
     * @access    public
     * @since     1.0
     */
    public function __construct( $user_name = '', $api_key = '' ) {

      if ( $user_name == '' ) {
        $this->set_error( 'user_name', __( 'Please enter your Envato Marketplace Username.', 'verifytheme' ) );
      }

      if ( $api_key == '' ) {
        $this->set_error( 'api_key', __( 'Please enter your Envato Marketplace API Key.', 'verifytheme' ) );
      }

      $this->user_name  = $user_name;
      $this->api_key    = $api_key;

    }

    /**
     * Get private user data.
     *
     * @param     string      Available sets: 'vitals', 'earnings-and-sales-by-month', 'statement', 'recent-sales', 'account', 'verify-purchase', 'download-purchase', 'wp-list-themes', 'wp-download'
     * @param     string      The buyer/author username to test against.
     * @param     string      Additional set data such as purchase code or item id.
     * @param     bool        Allow API calls to be cached. Default false.
     * @param     int         Set transient timeout. Default 300 seconds (5 minutes).
     * @return    array       An array of values (possibly cached) from the requested set, or an error message.
     *
     * @access    public
     * @since     1.0
     * @updated   1.3
     */
    public function private_user_data( $set = '', $user_name = '', $set_data = '', $allow_cache = false, $timeout = 300 ) {

      if ( $set == '' ) {
        $this->set_error( 'set', __( 'The API "set" is a required parameter.', 'verifytheme' ) );
      }

      if ( $user_name == '' ) {
        $user_name = $this->user_name;
      }

      if ( $user_name == '' ) {
        $this->set_error( 'user_name', __( 'Please enter your Envato Marketplace Username.', 'verifytheme' ) );
      }

      if ( $set_data !== '' ) {
        $set_data = ":$set_data";
      }

      if ( $errors = $this->api_errors() ) {
        return $errors;
      }

      $url = "https://marketplace.envato.com/api/edge/$user_name/$this->api_key/$set$set_data.json";

      /* set transient ID for later */
      $transient = substr( md5( $user_name . '_' . $set . $set_data ), 0, 16 );

      if ( $allow_cache ) {
        $cache_results = $this->set_cache( $transient, $url, $timeout );
        $results = $cache_results;
      } else {
        $results = $this->remote_request( $url );
      }

      if ( isset( $results->error ) ) {
        $this->set_error( 'error_' . $set, $results->error );
      }

      if ( $errors = $this->api_errors() ) {
        $this->clear_cache( $transient );
        return $errors;
      }

      if ( isset( $results->$set ) ) {
        return $results->$set;
      }

      return false;

    }

    /**
     * Used to list purchased themes.
     *
     * @param     bool        Allow API calls to be cached. Default true.
     * @param     int         Set transient timeout. Default 300 seconds (5 minutes).
     * @return    object      If user has purchased themes, returns an object containing those details.
     *
     * @access    public
     * @since     1.0
     * @updated   1.3
     */
    public function wp_list_themes( $allow_cache = true, $timeout = 300 ) {

      if ( $this->user_name == '' ) {
        $this->set_error( 'user_name', __( 'Please enter your Envato Marketplace Username.', 'verifytheme' ) );
      }

      $themes = $this->private_user_data( 'wp-list-themes', $this->user_name, '', $allow_cache, $timeout );

      if ( $errors = $this->api_errors() ) {
        return $errors;
      }

      return $themes;

    }

    /**
     * Used to download a purchased item.
     *
     * This method does not allow caching.
     *
     * @param     string      The purchased items id
     * @return    string|bool If item purchased, returns the download URL.
     *
     * @access    public
     * @since     1.0
     */
    public function wp_download( $item_id ) {

      if ( ! isset( $item_id ) ) {
        $this->set_error( 'item_id', __( 'The Envato Marketplace "item ID" is a required parameter.', 'verifytheme' ) );
      }

      $download = $this->private_user_data( 'wp-download', $this->user_name, $item_id );

      if ( $errors = $this->api_errors() ) {
        return $errors;
      } else if ( isset( $download->url ) ) {
        return $download->url;
      }

      return false;
    }

    /**
     * Retrieve the details for a specific marketplace item.
     *
     * @param     string      $item_id The id of the item you need information for.
     * @return    object      Details for the given item.
     *
     * @access    public
     * @since     1.0
     * @updated   1.3
     */
    public function item_details( $item_id, $allow_cache = true, $timeout = 300 ) {

      $url = preg_replace( '/set/i', 'item:' . $item_id, $this->public_url );

      /* set transient ID for later */
      $transient = substr( md5( 'item_' . $item_id ), 0, 16 );

      if ( $allow_cache ) {
        $cache_results = $this->set_cache( $transient, $url, $timeout );
        $results = $cache_results;
      } else {
        $results = $this->remote_request( $url );
      }

      if ( isset( $results->error ) ) {
        $this->set_error( 'error_item_' . $item_id, $results->error );
      }

      if ( $errors = $this->api_errors() ) {
        $this->clear_cache( $transient );
        return $errors;
      }

      if ( isset( $results->item ) ) {
        return $results->item;
      }

      return false;

    }

    /**
     * Set cache with the Transients API.
     *
     * @link      http://codex.wordpress.org/Transients_API
     *
     * @param     string      Transient ID.
     * @param     string      The URL of the API request.
     * @param     int         Set transient timeout. Default 300 seconds (5 minutes).
     * @return    mixed
     *
     * @access    public
     * @since     1.3
     */
    public function set_cache( $transient = '', $url = '', $timeout = 300 ) {

      if ( $transient == '' || $url == '' ) {
        return false;
      }

      /* keep the code below cleaner */
      $transient = $this->validate_transient( $transient );
      $transient_timeout = '_transient_timeout_' . $transient;

      /* set original cache before we destroy it */
      $old_cache = get_option( $transient_timeout ) < time() ? get_option( $transient ) : '';

      /* look for a cached result and return if exists */
      if ( false !== $results = get_transient( $transient ) ) {
        return $results;
      }

      /* create the cache and allow filtering before it's saved */
      if ( $results = apply_filters( 'envato_api_set_cache', $this->remote_request( $url ), $transient ) ) {
        set_transient( $transient, $results, $timeout );
        return $results;
      }

      return false;

    }

    /**
     * Clear cache with the Transients API.
     *
     * @link      http://codex.wordpress.org/Transients_API
     *
     * @param     string      Transient ID.
     * @return    void
     *
     * @access    public
     * @since     1.3
     */
    public function clear_cache( $transient = '' ) {

      delete_transient( $transient );

    }

    /**
     * Helper function to validate transient ID's.
     *
     * @param     string      The transient ID.
     * @return    string      Returns a DB safe transient ID.
     *
     * @access    public
     * @since     1.3
     */
    public function validate_transient( $id = '' ) {

      return preg_replace( '/[^A-Za-z0-9\_\-]/i', '', str_replace( ':', '_', $id ) );

    }

    /**
     * Helper function to set error messages.
     *
     * @param     string      The error array id.
     * @param     string      The error message.
     * @return    void
     *
     * @access    public
     * @since     1.0
     */
    public function set_error( $id, $error ) {

      $this->errors['errors'][$id] = $error;

    }

    /**
     * Helper function to return the set errors.
     *
     * @return    array       The errors array.
     *
     * @access    public
     * @since     1.0
     */
    public function api_errors() {

      if ( ! empty( $this->errors['errors'] ) ) {
        return $this->errors['errors'];
      }

    }

    /**
     * Helper function to query the marketplace API via wp_remote_request.
     *
     * @param     string      The url to access.
     * @return    object      The results of the wp_remote_request request.
     *
     * @access    private
     * @since     1.0
     */
    protected function remote_request( $url ) {

      if ( empty( $url ) ) {
        return false;
      }

      $args = array(
        'headers'    => array( 'Accept-Encoding' => '' ),
        'timeout'    => 30,
        'user-agent' => 'Toolkit/1.8.0',
      );

      $args['sslverify'] = false;

      $request = wp_safe_remote_request( $url, $args );

      if ( is_wp_error( $request ) ) {
      	echo $request->get_error_message();
      	return false;
      }

      $data = json_decode( $request['body'] );

      if ( $request['response']['code'] == 200 ) {
        return $data;
      } else {
        $this->set_error( 'http_code', $request['response']['code'] );
      }

      if ( isset( $data->error ) ) {
        $this->set_error( 'api_error', $data->error );
      }

      return false;
    }

    /**
     * Helper function to print arrays to the screen ofr testing.
     *
     * @param     array       The array to print out
     * @return    string
     *
     * @access    public
     * @since     1.0
     */
    public function pretty_print( $array ) {

      echo '<pre>';
      print_r( $array );
      echo '</pre>';

    }
  }
endif;

/* End Envato_Protected_API class */

class BearsthemesAPI {
    var $baseUrl;
    var $session;

    function __construct($baseUrl='http://api.bearsthemes.com/endpoint') {
        $this->baseUrl = $baseUrl;
        $this->session = null;
    }

    /**
     * Opens / initializes the curl connection.
     *
     * @return Boolean
     */
    function open() {
        if (empty($this->session)) {
            $this->session = curl_init();

            return true;
        }

        return false;
    }

    /**
     * Closes the curl connection
     *
     * @return Boolean
     */
    function close() {
        if(!empty($this->session)) {
            curl_close($this->session);

            return true;
        }

        return false;
    }

    /**
     * Check if baseUrl is available
     *
     * @return Boolean
     */
    function isBaseAvailable() {
        return $this->request($this->baseUrl) != null;
    }

    /**
     * Send GET request to any endpoint.
     *
     * @param String $endpoint
     * @param Array $headers
     * @param Array $curlParams
     *
     * @return String || null
     */
    function request($endpoint, $headers=array(), $curlParams=array()) {
        $this->open();

        if (empty($headers)) { $headers = array(); }

        $cParams = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => str_replace(
                "\0",
                "",
                $this->baseUrl . '/' . $endpoint
            ),
            CURLOPT_USERAGENT => 'Bearsthemes',
            CURLOPT_HTTPHEADER => $headers
        );

        if (!empty($curlParams)) { $cParams += $curlParams; }
        try {
            foreach (array_keys($cParams) as $param) {
                if (empty($cParams[$param])) { continue; }

                curl_setopt($this->session, $param, $cParams[$param]);
            }

            $resp = curl_exec($this->session);
        } catch (Exception $e) {
            return null;
        }

        return $resp;
    }

    /**
     * Send POST request to any endpoint
     *
     * @param String $endpoint
     * @param Array $fields - [key => value]
     * @param Array $headers
     */
    function requestPost($endpoint, $fields, $headers=array()) {
        $this->open();

        $fields_string = '';

        /* Turning $fields into an array encoded query ($fields_string) */
        foreach($fields as $key => $value) {
            $fields_string .= urlencode($key) . '=' . urlencode($value) . '&';
        }

        $fields_string = rtrim($fields_string, '&');

        return $this->request($endpoint, $headers, array(
            47 => sizeof($fields),
            10015 => $fields_string
        ));
    }
}
// End BearsthemesAPI Class

if (!class_exists('EnvatoMarket')):
  class EnvatoMarket extends BearsthemesAPI {

    var $api_key;
    function __construct($baseUrl='https://api.envato.com/v3/market') {
        parent::__construct($baseUrl);
    }

    /**
     * Set API key.
     *
     * @param String $api_key
     *
     * @return Boolean
     */
    function setAPIKey($api_key) {
        if (empty($api_key)) { return false; }

        $this->api_key = $api_key;

        return true;
    }

    /**
     * Get version of specified wordpress theme.
     *
     * @param String $id
     *
     * @return String | null
     */
    function getThemeVersion($id) {
        $response = $this->request("/catalog/item?id=$id", array(
            "Authorization: Bearer $this->api_key"
        ));

        try {
            $response = json_decode($response);
        } catch (Exception $e) {
            return null;
        }

        if (empty($response)) {
            return null;
        }

        if (!isset($response->wordpress_theme_metadata)) {
            return null;
        }

        if (!isset($response->wordpress_theme_metadata->version)) {
            return null;
        }

        return $response->wordpress_theme_metadata->version;
    }

    /**
     * Get stored data about current installation
     *
     * @return Array || null
     */
    function getToolkitData() {
        $option = get_option('verifytheme_settings');
        //$option = (Array)json_decode($option);

        if (!empty($option)) {
            return (Array)$option;
        }

        return null;
    }
    /**
     * Set stored data about current installation
     *
     * @param String $option
     */
    function setToolkitData($option = null) {
        update_option('verifytheme_settings',$option);
    }

    /**
     * Check if data about current installation is empty
     *
     * @return Boolean
     */
    function toolkitDataEmpty() {
        $data = $this->getToolkitData();

        if (empty($data)) { return true; }

        if (
            empty($data['user_name']) ||
            empty($data['api_key']) ||
            empty($data['purchase_code'])
        ) { return true; }
    }

    /**
     * Used to check if an update is available for a specific theme.
     * This function compares the local theme version with the remote version.
     *
     * @param String $id
     *
     * @return True
     */
    function updateExistsForTheme($id) {
        $local_version = wp_get_theme()->get('Version');
        $remote_version = $this->getThemeVersion($id);

        return $local_version != $remote_version && (
            !empty($local_version) && !empty($remote_version)
        );

        return $local_version != $remote_version;
    }

    /**
     * Get download for certain item using purchase_code.
     *
     * @param String $item_id
     * @param String $purchase_code
     */
    function getDownload($item_id, $purchase_code) {
        $response = $this->request(
            "/buyer/list-purchases",
            array(
                "Authorization: Bearer $this->api_key"
            )
        );

        return $response;
    }
  }
endif;

//  End EnvatoMarket Class

if (!class_exists('BearsthemesCommunicator')):
  class BearsthemesCommunicator extends BearsthemesAPI {
    var $items;

    function __construct($baseUrl='http://api.bearsthemes.com') {
        parent::__construct($baseUrl);
    }

    /**
     * Regsiter a purchaseCode to a domain.
     *
     * @param String $purchaseCode
     * @param String $domain
     *
     * @return Integer - ID of the inserted connection
     */
    function registerDomain($purchaseCode, $domain, $user_name) {
        $resp = $this->requestPost("license/add_license.php", array(
            'purchase_code' => $purchaseCode,
            'domain' => $domain,
            'user_name' => $user_name,
        ));
        return $resp;
        return empty($resp) ? null : $resp;
    }

    /**
     * Unregister / delete domain connaction from purchaseCode.
     *
     * @param String $purchaseCode
     *
     * @return Boolean
     */
    function unRegisterDomains($purchaseCode) {
        $resp = $this->requestPost("license/delete_license.php", array(
            'purchase_code' => $purchaseCode,
        ));
        if (empty($resp)) { return false; }

        return substr_count(strtolower($resp), "true") > 0 ||
            substr_count(strtolower($resp), "1") > 0;
    }

    /**
     * Get domains where this theme is used with same
     * purchase_code.
     *
     * @param String $purchaseCode
     *
     * @return Array<String> || null
     */
    function getConnectedDomains($purchaseCode) {
        $resp = $this->requestPost("license/get_license.php", array(
            'purchase_code' => $purchaseCode
        ));
        if (empty($resp)) { return null; }

        return $resp;
    }

    /**
     * Get information about purchase_code
     *
     * @param String $purchaseCode
     *
     * @return Object || null
     */
    function getPurchaseInformation($purchaseCode) {
        $response = $this->requestPost("license/check_purchase.php", array(
            'purchase_code' => $purchaseCode
        ));
        if (empty($response)) { return null; }
        $decoded = json_decode($response);
        if (empty($decoded)) { return null; }

        $decoded = (Array) $decoded;

        if (!isset($decoded['verify-purchase'])) { return null; }
        if (empty($decoded['verify-purchase'])) { return null; }

        return $decoded;
    }

    /**
     * Check if purchase_code is valid.
     *
     * @param String $purchaseCode
     *
     * @return Boolean
     */
    function isPurchaseCodeLegit($purchaseCode) {
        $get_info = $this->getPurchaseInformation($purchaseCode);
        return !empty($get_info);
    }
  }
endif;
// End BearsthemesCommunicator Class

// Helper

function isInstallationLegit( $data = false ) {
	if (!class_exists('EnvatoMarket')) {
		return;
	}

    $communicator = new BearsthemesCommunicator();

    $envato = new EnvatoMarket();
    $data = $data ? $data : $envato->getToolkitData();

    if(!$data) return false;

    $server_name = empty($_SERVER['SERVER_NAME']) ?
        $_SERVER['HTTP_HOST']: $_SERVER['SERVER_NAME'];

    if (
        substr_count($server_name, '.dev') > 0 ||
        substr_count($server_name, '.local') > 0
    ) { return true; }

    if (isset($data['api_key'])) {
        if (!empty($data['purchase_code'])) {
            $connected_domain = $communicator->getConnectedDomains(
                $data['purchase_code']
            );

            // Return early if the connected domain is a subdomain of the current
            // domain we are trying to register (or viceversa)
            $real_con_domain = verifythemeGetDomain( $connected_domain );
            $real_current_domain = verifythemeGetDomain( $server_name );

            if ( $real_con_domain === $real_current_domain ) {
            	return true;
            }

            if (
                $connected_domain != $server_name &&
                !empty($connected_domain) &&
                substr_count($connected_domain, '.dev') == 0 &&
                substr_count($connected_domain, '.local') == 0
            ) {
                return false;
            }
        }
    }

    return true;
}

function requiredDataEmpty() {
  $communicator = new BearsthemesCommunicator();

	if (!class_exists('EnvatoMarket')) {
		return;
	}

  $envato = new EnvatoMarket();
  return $envato->toolkitDataEmpty();
}

/**
 * Extract domain from hostname
 */
function verifythemeGetDomain( $url ) {
	$pieces = parse_url( $url );
	$domain = isset( $pieces[ 'path' ] ) ? $pieces[ 'path' ] : '';

	if ( preg_match( '/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $domain, $regs ) ) {
		return $regs[ 'domain' ];
	}

	return false;
}
/**
 * Check if our purchase code is connected to any domain.
 * If there's not a domain attached to the purchase code,
 * empty the license data on this installation.
 */
function licenseNeedsDeactivation( $toolkitData ) {
	if ( $toolkitData && isset( $toolkitData[ 'purchase_code' ] ) ) {
		$communicator = new BearsthemesCommunicator();
		$connected_domain = $communicator->getConnectedDomains( $toolkitData[ 'purchase_code' ] );

		if ( ! $connected_domain ) {
			delete_option( 'verifytheme_settings' );

			return true;
		} else {
			return false;
		}
	}

	return false;
}
// End Helper

class VerifyTheme {
    public $isInstallationLegit = false;
    function __construct() {
      // create custom plugin settings menu
        add_action('admin_menu', array( $this, 'verifytheme_menu' ));
        add_action('admin_init', array( $this, 'verifytheme_page_init' ));
        add_action( 'admin_enqueue_scripts', array( $this, 'verifytheme_admin_script' ));
        $this->isInstallationLegit();
    		if ( !$this->isInstallationLegit ){
    			add_action( 'admin_notices', array( $this, 'verifytheme_admin_notice__warning' ));
    		}
    }
    // check theme activate
    function isInstallationLegit(){
      $envato = new EnvatoMarket();
      $envato->setAPIKey(ENVATO_KEY);
      $toolkitData = $envato->getToolkitData();
      $installationLegit = isInstallationLegit();
      if ( $toolkitData && $installationLegit ) $this->isInstallationLegit = true;
      return $this->isInstallationLegit;
    }
  	// function notice if theme not active
  	function verifytheme_admin_notice__warning() {
  		$class = 'notice notice-warning is-dismissible';
  		$setting_page = admin_url('options-general.php?page=verifytheme_settings');
  		$message = __( '<b>Important notice:</b> In order to receive all benefits of our theme, you need to activate your copy of the theme. <br />By activating the theme license you will unlock premium options - import demo data, install & update plugins and official support. Please visit <a href="'.$setting_page.'">Envato Settings</a> page to activate your copy of the theme', 'verifytheme' );
  		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses( $message, array('b' => array(), 'br' => array(), 'a' => array('href' => array())) ) );
  	}

    // add style admin
  	function verifytheme_admin_script() {
      wp_register_style( 'verifytheme', get_stylesheet_directory_uri() . '/framework/verifytheme.css', false );
      wp_enqueue_style( 'verifytheme' );
  	}
    /**
     * Menu admin
     *
     */

    function verifytheme_menu() {
      add_options_page(
          'Envato Settings',
          'Envato Settings',
          'manage_options',
          'verifytheme_settings',
          array( $this, 'verifytheme_settings_page' )
      );
    }
    /**
     * Options page callback
     */
    public function verifytheme_settings_page(){
        $envato = new EnvatoMarket();
        $communicator = new BearsthemesCommunicator();
        $envato->setAPIKey(ENVATO_KEY);
        $toolkitData = $envato->getToolkitData();
        if ( isset( $_POST[ 'change_license' ] ) && class_exists( 'BearsthemesCommunicator' ) ) {
          $is_deregistering_license = true;
					$communicator->unRegisterDomains( $toolkitData[ 'purchase_code' ] );
					delete_option( 'verifytheme_settings' );
				}
        $license_already_in_use = false;
  			// This flag checks if we are deregistering a purchase code - We need
  			// it becasuse the $communicator->unRegisterDomains()
  			// runs after the form submission
				$is_deregistering_license = false;

				$needs_to_be_deactivated = licenseNeedsDeactivation( $toolkitData );

				if ( $needs_to_be_deactivated ) {
					$toolkitData = false;
				}

				$installationLegit = isInstallationLegit();

				if ( ! $installationLegit ) {
					$license_already_in_use = true;
				}
        $other_attributes = '';
        $register_button_text = __( 'Register your theme', 'verifytheme' );
        if ( $toolkitData && $installationLegit ){
          $other_attributes = 'disabled';
          $register_button_text = __( 'Activated on this domain', 'verifytheme' );
          $this->isInstallationLegit = true;
        }
        $type = 'primary';
        $name = 'submit';
        $wrap = true;
        $this->options = get_option( 'verifytheme_settings' );
        ?>
        <div class="wrap verifytheme_wrap">
            <form class="verifytheme_settings_form" method="post" action="options.php">
              <?php
                  // This prints out all hidden setting fields
                  settings_fields( 'verifytheme_settings' );
                  do_settings_sections( 'verifytheme_settings' );
                  submit_button($register_button_text, $type, $name, $wrap, $other_attributes);
              ?>
              <?php if ( $toolkitData && ! $is_deregistering_license && ! $license_already_in_use ) : ?>
              <p class="change_license_wrap">
                <input name="change_license_tmp" onclick="document.getElementById('change_license_btn').click();" id="change_license_tmp" class="button" value="<?php esc_attr_e('Deregister your product','verifytheme'); ?>" type="button">
              </p>
            <?php endif; ?>
            </form>
            <form style="display: none" id="change_license_form" method="POST">
              <button id="change_license_btn" type="submit" class="button button-primary" name="change_license"><?php echo esc_html__( 'Deregister your product', 'verifytheme' ); ?></button>
            </form>
        </div>
        <?php
    }
    /**
     * Register and add settings
     */
    public function verifytheme_page_init()
    {
        register_setting(
            'verifytheme_settings', // Option group
            'verifytheme_settings', // Option name
            array( $this, 'verifytheme_sanitize' ) // Sanitize
        );

        add_settings_section(
            'verifytheme_general_section', // ID
            'Envato Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'verifytheme_settings' // Page
        );

        add_settings_field(
            'user_name', // ID
            'Marketplace Username', // Title
            array( $this, 'verifytheme_user_name_callback' ), // Callback
            'verifytheme_settings', // Page
            'verifytheme_general_section' // Section
        );

        add_settings_field(
            'api_key',
            'Secret API Key',
            array( $this, 'verifytheme_api_key_callback' ),
            'verifytheme_settings',
            'verifytheme_general_section'
        );

        add_settings_field(
            'purchase_code',
            'Purchase code',
            array( $this, 'verifytheme_purchase_code_callback' ),
            'verifytheme_settings',
            'verifytheme_general_section'
        );
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function verifytheme_sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['user_name'] ) )
            $new_input['user_name'] = sanitize_text_field( $input['user_name'] );

        if( isset( $input['api_key'] ) )
            $new_input['api_key'] = sanitize_text_field( $input['api_key'] );

        if( isset( $input['purchase_code'] ) )
            $new_input['purchase_code'] = sanitize_text_field( $input['purchase_code'] );

        $register_error = get_settings_errors('verifytheme_settings');
        $message = '';
        $type = 'error';
        if( isset( $input['force_activation'] ) )
            $new_input['force_activation'] = true;


        $communicator = new BearsthemesCommunicator();
        $envato = new EnvatoMarket();
        $envato->setAPIKey(ENVATO_KEY);

        $toolkitData = $envato->getToolkitData();
        $connected_domain = $communicator->getConnectedDomains( $toolkitData[ 'purchase_code' ] );

        $toolkit = new Envato_Protected_API(
            $new_input['user_name'],
            $new_input['api_key']
        );

        // Do we need this line? //// Yes, we do!
        $download_url = $toolkit->wp_download(ITEM_ID);

        $errors = $toolkit->api_errors();

        if(!empty($errors)):
          $message .= $errors['api_error']."<br />";
        endif;

        $ok_purchase_code = $communicator->isPurchaseCodeLegit($new_input['purchase_code']);

        if ($ok_purchase_code) {
            $data = array(
                'user_name' => $new_input['user_name'],
                'purchase_code' => $new_input['purchase_code'],
                'api_key' => $new_input['api_key']
            );
        } else {
            $message .= "Invalid purchase code<br />";
        }

        $already_in_use = ! isInstallationLegit( $data );
        if(!empty($message)):
          if(!$register_error):
            add_settings_error(
                'verifytheme_settings',
                esc_attr( 'settings_updated' ),
                $message,
                $type
            );
            return array();
          endif;
        else:
          if ( ! $already_in_use || $force_activation ) {
            $server_name = empty($_SERVER['SERVER_NAME']) ? $_SERVER['HTTP_HOST']: $_SERVER['SERVER_NAME'];

            // Deregister any connected domain first
            $communicator->unRegisterDomains( $new_input[ 'purchase_code' ] );

            $communicator->registerDomain($new_input['purchase_code'], $server_name, $data['user_name']);
          }else{
            $message .= sprintf(wp_kses( __( 'This product is in use on another domain: <span>%s</span><br />', 'verifytheme' ), array( 'span' => array(), 'br' => array() ) ), $connected_domain );
            $message .= sprintf(esc_html__('Are you using this theme for a new site? Please purchase a %s ', 'verifytheme' ), '<a tabindex="-1" href="' . esc_url( 'http://themeforest.net/cart/add_items?ref=bearsthemes&item_ids=' ) .ITEM_ID.'" target="_blank">'.esc_html__('new license','verifytheme').'</a>');
            add_settings_error(
                'verifytheme_settings',
                esc_attr( 'settings_updated' ),
                $message,
                $type
            );
          }
        endif;
        return $new_input;
    }

    /**
     * Print the Section text
     */
    public function print_section_info()
    {
        _e('To obtain your API Key, visit your "My Settings" page on any of the Envato Marketplaces. Once a valid connection has been made any changes to the API key below for this username will not effect the results for 5 minutes because they\'re cached in the database. If you have already made an API connection and just purchase a theme and it\'s not showing up, wait five minutes and refresh the page. If the theme is still not showing up, it\'s possible the author has not made it available for auto install yet.','verifytheme');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function verifytheme_user_name_callback()
    {
        printf(
            '<input type="text" id="user_name" required name="verifytheme_settings[user_name]" value="%s" /><br /><small>%s<a target="_blank" href="%s">%s</a>.</small>',
            isset( $this->options['user_name'] ) ? esc_attr( $this->options['user_name']) : '', esc_html__('Please insert your Envato username. ','verifytheme'), esc_url('//bearsthemes.com/product-registration/'), esc_html__('More info','verifytheme')
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function verifytheme_api_key_callback()
    {
        printf(
            '<input type="text" id="api_key" required name="verifytheme_settings[api_key]" value="%s" /><br /><small>%s<a target="_blank" href="%s">%s</a>.</small>', isset( $this->options['api_key'] ) ? esc_attr( $this->options['api_key']) : '', esc_html__('Please insert your Envato API Key. ','verifytheme'), esc_url('//bearsthemes.com/product-registration/'), esc_html__('More info','verifytheme')
        );
    }
    /**
     * Get the settings option array and print one of its values
     */
    public function verifytheme_purchase_code_callback()
    {
        printf(
            '<input type="text" id="purchase_code" required name="verifytheme_settings[purchase_code]" value="%s" /><br /><small>%s<a target="_blank" href="%s">%s</a>.</small>',
            isset( $this->options['purchase_code'] ) ? esc_attr( $this->options['purchase_code']) : '', esc_html__('Please insert your Envato purchase code. ','verifytheme'), esc_url('//bearsthemes.com/product-registration/'), esc_html__('More info','verifytheme')
        );
    }
}
