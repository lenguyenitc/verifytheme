# VerifyTheme

Envato-style license verification for themes with a small admin UI for activation/deactivation.

This repository contains:
- Envato_License_Manager — API client, caching and option persistence.
- VerifyTheme_Admin — admin page, AJAX handlers and admin asset enqueues.
- Example assets: verifytheme.js, verifytheme.css

## Quick configuration

Define these constants in your theme (e.g. in functions.php). Do not commit secrets.

```php
define( 'THEME_LICENSE_ITEM_ID', '12345678' );    // required: your item id
define( 'THEME_LICENSE_API_URL', 'https://api.example/' ); // required: API base URL
define( 'THEME_LICENSE_API_KEY', 'your_api_secret_key' );  // required: API key (secret)
```

The code falls back to older constants if needed: ITEM_ID, API_URL, API_SECRET_KEY.

## Install & initialize (recommended)

Place the library under your theme (this repo uses install/license-manager/). Example bootstrap:

```php
/**
 * Verify purchase code
 */
require get_template_directory() . '/install/license-manager/VerifyTheme.php';

// Initialize the admin UI and AJAX handlers
add_action( 'after_setup_theme', function() {
    if ( class_exists( 'VerifyTheme_Admin' ) ) {
        VerifyTheme_Admin::init();
    }
} );
```

This registers:
- admin submenu page,
- AJAX endpoints: wp_ajax_verifytheme_activate, wp_ajax_verifytheme_deactivate,
- enqueues admin CSS/JS on the settings page.

## Programmatic usage

You can instantiate and use the manager directly:

```php
$mgr = new Envato_License_Manager( [
    'item_id' => THEME_LICENSE_ITEM_ID,
    'api_url' => THEME_LICENSE_API_URL,
    'api_key' => THEME_LICENSE_API_KEY,
    // optional: 'option' => '_my_option_name', 'http_client' => callable
] );

$state = $mgr->get_license_state();     // stored license state (array|null)
$ok = $mgr->is_activated();             // bool
$res = $mgr->activate( 'PURCHASE-CODE' );   // true or WP_Error
$res = $mgr->deactivate();                 // true or WP_Error
$info = $mgr->get_connected_domains( 'PURCHASE-CODE' );
```

Refer to VerifyTheme.php for the full public API.

## Admin assets / enqueue — Important

VerifyTheme_Admin::enqueue_admin_assets enqueues:
- CSS: get_template_directory_uri() . '/install/license-manager/verifytheme.css'
- JS:  get_template_directory_uri() . '/install/license-manager/verifytheme.js'

If you move or rename files, or use build/patch scripts that produce hashed/versioned filenames, update the paths inside VerifyTheme_Admin::enqueue_admin_assets to point to the generated assets. Preserve wp_localize_script so the JS receives verifytheme.ajax_url, verifytheme.nonce and localized strings.

When using build/patch scripts:
- ensure final filenames are referenced by the enqueues,
- keep script/style handles consistent if referenced elsewhere,
- consider using filemtime() or version constants to bust cache.

## Security & rate limiting

AJAX handlers enforce:
- capability: current_user_can( 'manage_options' )
- nonce: 'verifytheme_action'
- transient-based rate limiting (default: 5 attempts per hour per user or IP)

## Files in this repo

- VerifyTheme.php — main implementation (Envato_License_Manager & VerifyTheme_Admin)
- verifytheme.js — admin-side JS (AJAX UI)
- verifytheme.css — admin styles
- README.md — this file

## Notes

- Keep API keys secret and out of version control.
- Update item id and API credentials to your values.
- If embedding in another project, verify enqueue paths and constants.
- Example admin bootstrap is shown above.
