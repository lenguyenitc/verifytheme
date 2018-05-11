This library provides easy way to verify envato purchase code

### Usage
First, include the library in your theme.

`require 'VerifyTheme.php';`

Next, create a new instance of the class:

`$VerifyTheme = new VerifyTheme();`

And that's really it. You now have access to all of the available functions.

### Examples

#### Quickly get result validate
    $VerifyTheme = new VerifyTheme();
    $isInstallationLegit = $VerifyTheme->isInstallationLegit(); // return true if your copy theme is activated and false of not activate
