<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

class limit_purchase
{
    var $config;

    function __construct()
    {
        $this->loadConfig();
    }

    function loadConfig()
    {
        $this->config = [];
        $configDetails = Capsule::table('mod_limit_purchase_config')->get();

        foreach ($configDetails as $config_detail) {
            $this->config[$config_detail->name] = $config_detail->value;
        }
    }

    function setConfig($name, $value)
    {
        $exists = Capsule::table('mod_limit_purchase_config')
            ->where('name', $name)
            ->exists();

        if ($exists) {
            Capsule::table('mod_limit_purchase_config')
                ->where('name', $name)
                ->update(['value' => $value]);
        } else {
            Capsule::table('mod_limit_purchase_config')
                ->insert(['name' => $name, 'value' => $value]);
        }

        $this->config[$name] = $value;
    }

    function getLimitedProducts()
    {
        $output = [];

        $limits = Capsule::table('mod_limit_purchase as l')
            ->join('tblproducts as p', 'p.id', '=', 'l.product_id')
            ->where('l.active', 1)
            ->select('l.*')
            ->get();

        foreach ($limits as $limit) {
            $output[$limit->product_id] = [
                'limit' => $limit->limit,
                'error' => $limit->error
            ];
        }

        return $output;
    }
}

// Hook: limit purchases + trial enforcement
function limit_purchase($vars)
{
    $errors = [];
    $lp = new limit_purchase();

    $pids = $lp->getLimitedProducts();
    $user_id = intval($_SESSION['uid']);
    $configs = $lp->config;

    $enforceTrial = isset($configs['enforce_trial_first']) && $configs['enforce_trial_first'] == '1';
    $forceRedirect = isset($configs['force_redirect_to_trial']) && $configs['force_redirect_to_trial'] == '1';
    $popupMessage = $configs['trial_popup_message'] ?? 'You have to take trial first before purchasing a paid package.';

    $trialProductIDs = Capsule::table('tblproducts')
        ->where('paytype', 'free')
        ->pluck('id')
        ->toArray();

    $hasTrial = false;
    if ($user_id && $enforceTrial) {
        $hasTrial = Capsule::table('tblhosting')
            ->where('userid', $user_id)
            ->whereIn('packageid', $trialProductIDs)
            ->whereIn('domainstatus', ['Active', 'Pending'])
            ->exists();
    }

    if (isset($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])) {
        $counter = $delete = [];

        foreach ($_SESSION['cart']['products'] as $i => $product_details) {
            $pid = $product_details['pid'];
            $isPaid = !in_array($pid, $trialProductIDs);

            if ($enforceTrial && !$hasTrial && $isPaid) {
                if ($forceRedirect && !empty($trialProductIDs)) {
                    $_SESSION['cart']['products'] = []; // clear cart
                    $_SESSION['trial_redirect_notice'] = $popupMessage;
                    header("Location: /cart.php?a=add&pid=" . $trialProductIDs[0]);
                    exit;
                } else {
                    $errors[] = $popupMessage;
                    continue;
                }
            }

            if (array_key_exists($pid, $pids)) {
                if (!isset($counter[$pid])) {
                    $counter[$pid] = 0;

                    if ($user_id) {
                        $counter[$pid] = Capsule::table('tblhosting')
                            ->where('userid', $user_id)
                            ->where('packageid', $pid)
                            ->count();
                    }
                }

                if ($pids[$pid]['limit'] <= intval($counter[$pid])) {
                    if (!isset($delete[$pid])) {
                        $product = Capsule::table('tblproducts')
                            ->where('id', $pid)
                            ->first(['name']);

                        if ($product) {
                            $delete[$pid] = $product;
                        }
                    }
                }

                $counter[$pid]++;
            }
        }

        foreach ($delete as $product_id => $product_details) {
            $errors[] = str_replace('{PNAME}', $product_details->name, $pids[$product_id]['error']);
        }
    }

    return $errors;
}

// Hook: delete limit when product is deleted
function limit_purchase_delete($vars)
{
    Capsule::table('mod_limit_purchase')
        ->where('product_id', $vars['pid'])
        ->delete();
}

// Show popup alert after redirect
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (isset($_SESSION['trial_redirect_notice'])) {
        $msg = addslashes($_SESSION['trial_redirect_notice']);
        unset($_SESSION['trial_redirect_notice']);
        return <<<HTML
<script>
    window.addEventListener('load', function () {
        alert("{$msg}");
    });
</script>
HTML;
    }
});

// Register hooks
add_hook('ShoppingCartValidateCheckout', 0, 'limit_purchase');
add_hook('ProductDelete', 0, 'limit_purchase_delete');
