!(function($){
    "use strict";

    function showMessage( $container, type, message ) {
        var $notice = $('<div/>', {
            'class': 'notice ' + ( type === 'success' ? 'notice-success' : 'notice-error' ) + ' is-dismissible'
        }).append( $('<p/>').text( message ) );

        $container.empty().append( $notice );
    }

    function setButtonStates( $input, $btnActivate, $btnDeactivate ) {
        var isActivated = $input.is(':disabled') || $input.data('activated') === true;

        $btnActivate.prop('disabled', isActivated);
        $input.prop('disabled', isActivated);
        $btnDeactivate.prop('disabled', ! isActivated);
    }

    // Helper: extract server error message from non-2xx responses
    function extractAjaxErrorMessage( xhr, defaultMsg ) {
        defaultMsg = defaultMsg || ( window.verifytheme && verifytheme.strings && verifytheme.strings.ajax_error ) || 'AJAX error. Please try again.';
        try {
            // WP sends JSON bodies via wp_send_json_error; jQuery populates responseJSON for parsed JSON
            if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
                return xhr.responseJSON.data.message;
            }
            // fallback: try parse responseText
            if ( xhr && xhr.responseText ) {
                var txt = xhr.responseText.trim();
                // try parse JSON
                var j = JSON.parse(txt);
                if ( j && j.data && j.data.message ) {
                    return j.data.message;
                }
                // else return raw text (short)
                if ( txt.length > 0 ) {
                    return txt.length > 200 ? txt.substr(0,200) + '...' : txt;
                }
            }
        } catch ( e ) {
            // ignore parse errors
        }
        return defaultMsg;
    }

    $(function(){
        var $doc = $(document);
        var $container = $('#verifytheme_message');
        var $input = $('#verify_purchase_code');
        var $btnActivate = $('#verify_activate');
        var $btnDeactivate = $('#verify_deactivate');

        // Keep existing import-demo behavior (if present)
       if ( typeof verifytheme !== 'undefined' && verifytheme.is_activated !== '1' ) {
          $('#Import_Pack_Container').on('click', '.__action-import-demo', function(e){
              e.stopImmediatePropagation();
              var confirm_message = window.verifytheme && verifytheme.strings && verifytheme.strings.confirm_import ? verifytheme.strings.confirm_import : 'Proceed?';
              var setting_page = window.verifytheme && verifytheme.setting_page ? verifytheme.setting_page : '/';
              if ( confirm(confirm_message) ) {
                  window.location.href = setting_page;
              }
          });
        }

        // If elements don't exist, nothing to do
        if ( ! $input.length || ! $btnActivate.length || ! $btnDeactivate.length ) {
            return;
        }

        // Initialize button states based on input disabled state or data attribute
        setButtonStates( $input, $btnActivate, $btnDeactivate );

        // Activate handler
        $btnActivate.on('click', function(e){
            e.preventDefault();

            if ( typeof verifytheme === 'undefined' || ! verifytheme.ajax_url || ! verifytheme.nonce ) {
                showMessage( $container, 'error', (verifytheme && verifytheme.strings && verifytheme.strings.ajax_error) || 'AJAX not configured.' );
                return;
            }

            var code = $input.val() ? $input.val().trim() : '';
            if ( code === '' ) {
                showMessage( $container, 'error', (verifytheme && verifytheme.strings && verifytheme.strings.please_enter_purchase_code) || 'Please enter a purchase code.' );
                return;
            }

            $btnActivate.prop('disabled', true);
            showMessage( $container, 'success', (verifytheme && verifytheme.strings && verifytheme.strings.verifying) || 'Verifying...' );

            $.post( verifytheme.ajax_url, {
                action: 'verifytheme_activate',
                purchase_code: code,
                nonce: verifytheme.nonce
            }, function( res ){
                if ( res && res.success ) {
                    showMessage( $container, 'success', (res.data && res.data.message) ? res.data.message : (verifytheme && verifytheme.strings && verifytheme.strings.license_activated) );
                    $input.data('activated', true);
                    setButtonStates( $input, $btnActivate, $btnDeactivate );
                    setTimeout(function(){ location.reload(); }, 1200);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (verifytheme && verifytheme.strings && verifytheme.strings.activation_failed);
                    showMessage( $container, 'error', msg );
                    $btnActivate.prop('disabled', false);
                }
            }, 'json' ).fail(function( xhr ){
                // parse server message if present (e.g. 429 Too Many Requests with JSON body)
                var msg = extractAjaxErrorMessage( xhr, (verifytheme && verifytheme.strings && verifytheme.strings.ajax_error) );
                showMessage( $container, 'error', msg );
                $btnActivate.prop('disabled', false);
            });
        });

        // Deactivate handler
        $btnDeactivate.on('click', function(e){
            e.preventDefault();

            var confirmText = (verifytheme && verifytheme.strings && verifytheme.strings.deactivate_confirm) || 'Are you sure you want to deregister this license on this site?';
            if ( ! confirm( confirmText ) ) {
                return;
            }

            if ( typeof verifytheme === 'undefined' || ! verifytheme.ajax_url || ! verifytheme.nonce ) {
                showMessage( $container, 'error', (verifytheme && verifytheme.strings && verifytheme.strings.ajax_error) || 'AJAX not configured.' );
                return;
            }

            $btnDeactivate.prop('disabled', true);
            showMessage( $container, 'success', (verifytheme && verifytheme.strings && verifytheme.strings.processing) || 'Processing...' );

            $.post( verifytheme.ajax_url, {
                action: 'verifytheme_deactivate',
                nonce: verifytheme.nonce
            }, function( res ){
                if ( res && res.success ) {
                    showMessage( $container, 'success', (res.data && res.data.message) ? res.data.message : (verifytheme && verifytheme.strings && verifytheme.strings.license_deactivated) );
                    $input.data('activated', false);
                    setButtonStates( $input, $btnActivate, $btnDeactivate );
                    setTimeout(function(){ location.reload(); }, 900);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (verifytheme && verifytheme.strings && verifytheme.strings.deactivation_failed);
                    showMessage( $container, 'error', msg );
                    $btnDeactivate.prop('disabled', false);
                }
            }, 'json' ).fail(function( xhr ){
                var msg = extractAjaxErrorMessage( xhr, (verifytheme && verifytheme.strings && verifytheme.strings.ajax_error) );
                showMessage( $container, 'error', msg );
                $btnDeactivate.prop('disabled', false);
            });
        });

        // Support dismissible notices created here
        $doc.on('click', '.notice.is-dismissible .notice-dismiss', function(){
            $(this).closest('.notice').fadeOut(200, function(){ $(this).remove(); });
        });
    });
})(jQuery);
