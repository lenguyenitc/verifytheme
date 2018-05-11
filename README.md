This library provides easy way to verify envato purchase code
### Notes
Replace `define("ITEM_ID","20473427");` by current item id

### Usage
First, include the library in your theme.

`require 'VerifyTheme.php';`

Replace `define("ITEM_ID","20473427");` by current item id

Define your Envato APIKey

`define("ENVATO_KEY","d49kexl70or1kr3trir4gka3qoae5eog");`


### Examples

#### Quickly install menu & settings panel then get result validate
    $VerifyTheme = new VerifyTheme();
    $isInstallationLegit = $VerifyTheme->isInstallationLegit(); // return true if your copy theme is activated and false of not activate
#### Instance class EnvatoMarket
    $envato = new EnvatoMarket();
#### Set APIKey
    $envato = new EnvatoMarket();
    $envato->setAPIKey(ENVATO_KEY);
#### Set envato data
    $option = array(
      'user_name' => $user_name,
      'purchase_code' => $purchase_code,
      'api_key' => $api_key
    );
    $toolkitData = $envato->setToolkitData(option);
#### Get envato data
    $toolkitData = $envato->getToolkitData();
#### Get connected domain by purchase code
    $communicator = new BearsthemesCommunicator();
    $connected_domain = $communicator->getConnectedDomains( $toolkitData[ 'purchase_code' ] );
#### Validate username & buyer api key
    $toolkit = new Envato_Protected_API(
        $toolkitData['user_name'],
        $toolkitData['api_key']
    );
    $errors = $toolkit->api_errors();
#### Validate purchase code
    $ok_purchase_code = $communicator->isPurchaseCodeLegit($toolkitData['purchase_code']);
#### Check purchase code already in use on other site
    $already_in_use = ! isInstallationLegit( $toolkitData );
#### Deregister connected domain
    $communicator->unRegisterDomains( $toolkitData[ 'purchase_code' ] );
#### Register new domain
    $server_name = empty($_SERVER['SERVER_NAME']) ? $_SERVER['HTTP_HOST']: $_SERVER['SERVER_NAME'];
    $communicator->registerDomain($toolkitData['purchase_code'], $server_name, $toolkitData['user_name']);
#### Check purchase code is installation legit
    $installationLegit = isInstallationLegit();
