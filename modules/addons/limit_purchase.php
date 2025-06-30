<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function limit_purchase_config() {
    return array(
        "name" => "Product Limiter",
        "description" => "This addon allows you to limit the purchase of products/services for each client",
        "version" => "2.0.0",
        "author" => "Sahanur Monal",
        "language" => "english",
    );
}

function limit_purchase_activate() {
    $error = [];

    try {
        Capsule::schema()->create('mod_limit_purchase_config', function ($table) {
            $table->string('name')->unique();
            $table->text('value');
        });

        Capsule::table('mod_limit_purchase_config')->insert([
            ['name' => 'localkey', 'value' => ''],
            ['name' => 'version_check', 'value' => '0'],
            ['name' => 'version_new', 'value' => ''],
            ['name' => 'enforce_trial_first', 'value' => '0'],
            ['name' => 'force_redirect_to_trial', 'value' => '0'],
            ['name' => 'trial_popup_message', 'value' => 'You have to take trial first before purchasing a paid package.'],
        ]);

        Capsule::schema()->create('mod_limit_purchase', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->default(0);
            $table->integer('limit')->default(0);
            $table->string('error');
            $table->boolean('active')->default(0);
        });

    } catch (Exception $e) {
        $error[] = "Error: " . $e->getMessage();
    }

    return array(
        'status' => empty($error) ? 'success' : 'error',
        'description' => empty($error) ? '' : implode(" -> ", $error),
    );
}

function limit_purchase_deactivate() {
    $error = [];

    try {
        Capsule::schema()->dropIfExists('mod_limit_purchase');
        Capsule::schema()->dropIfExists('mod_limit_purchase_config');
    } catch (Exception $e) {
        $error[] = "Error: " . $e->getMessage();
    }

    return array(
        'status' => empty($error) ? 'success' : 'error',
        'description' => empty($error) ? '' : implode(" -> ", $error),
    );
}

function limit_purchase_output($vars) {
    $modulelink = $vars['modulelink'];
    $action = $_GET['action'] ?? '';
    $editLimit = null;

    echo '<h2>Product Purchase Limiter Settings</h2>';

    // Save settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $enforce = isset($_POST['enforce_trial_first']) ? '1' : '0';
        $redirect = isset($_POST['force_redirect_to_trial']) ? '1' : '0';
        $popup_msg = trim($_POST['trial_popup_message'] ?? '');

        Capsule::table('mod_limit_purchase_config')->updateOrInsert(
            ['name' => 'enforce_trial_first'],
            ['value' => $enforce]
        );
        Capsule::table('mod_limit_purchase_config')->updateOrInsert(
            ['name' => 'force_redirect_to_trial'],
            ['value' => $redirect]
        );
        Capsule::table('mod_limit_purchase_config')->updateOrInsert(
            ['name' => 'trial_popup_message'],
            ['value' => $popup_msg]
        );

        echo '<div class="infobox">Settings updated.</div>';
    }

    // Handle delete
    if ($action === 'delete' && isset($_GET['id'])) {
        Capsule::table('mod_limit_purchase')->where('id', intval($_GET['id']))->delete();
        echo '<div class="successbox">Limit deleted successfully.</div>';
    }

    // Handle edit fetch
    if ($action === 'edit' && isset($_GET['id'])) {
        $editLimit = Capsule::table('mod_limit_purchase')->find(intval($_GET['id']));
    }

    // Handle add
    if ($action === 'addlimit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $limit = intval($_POST['limit'] ?? 0);
        $error = trim($_POST['error'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($product_id && $limit && $error) {
            $exists = Capsule::table('mod_limit_purchase')->where('product_id', $product_id)->exists();

            if (!$exists) {
                Capsule::table('mod_limit_purchase')->insert([
                    'product_id' => $product_id,
                    'limit' => $limit,
                    'error' => $error,
                    'active' => $active
                ]);
                echo '<div class="successbox">Limit added successfully.</div>';
            } else {
                echo '<div class="errorbox">Limit already exists for selected product.</div>';
            }
        } else {
            echo '<div class="errorbox">All fields are required.</div>';
        }
    }

    // Handle update
    if ($action === 'editlimit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        $limit = intval($_POST['limit'] ?? 0);
        $error = trim($_POST['error'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($id && $product_id && $limit && $error) {
            Capsule::table('mod_limit_purchase')
                ->where('id', $id)
                ->update([
                    'product_id' => $product_id,
                    'limit' => $limit,
                    'error' => $error,
                    'active' => $active
                ]);
            echo '<div class="successbox">Limit updated successfully.</div>';
        } else {
            echo '<div class="errorbox">All fields are required to update limit.</div>';
        }
    }

    // Get config values
    $trialEnforced = Capsule::table('mod_limit_purchase_config')
        ->where('name', 'enforce_trial_first')->value('value');

    $redirectEnabled = Capsule::table('mod_limit_purchase_config')
        ->where('name', 'force_redirect_to_trial')->value('value');

    $popupMessage = Capsule::table('mod_limit_purchase_config')
        ->where('name', 'trial_popup_message')->value('value');

    // Settings form
    echo '<form method="post">';
    echo '<input type="hidden" name="save_settings" value="1">';
    echo '<label><input type="checkbox" name="enforce_trial_first" value="1"' . ($trialEnforced ? ' checked' : '') . '> Require trial before ordering paid product</label><br><br>';
    echo '<label><input type="checkbox" name="force_redirect_to_trial" value="1"' . ($redirectEnabled ? ' checked' : '') . '> Force redirect to trial product if not taken</label><br><br>';
    echo 'Popup Message: <input type="text" name="trial_popup_message" style="width:400px;" value="' . htmlspecialchars($popupMessage) . '"><br><br>';
    echo '<input type="submit" value="Save Settings" class="btn btn-primary">';
    echo '</form>';

    echo '<hr>';
    echo '<h2>Product Limit Rules</h2>';

    $limits = Capsule::table('mod_limit_purchase')->get();
    $products = Capsule::table('tblproducts')->pluck('name', 'id');

    echo '<table class="table table-bordered"><thead><tr><th>Product</th><th>Limit</th><th>Error Message</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
    foreach ($limits as $limit) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($products[$limit->product_id] ?? 'Unknown') . '</td>';
        echo '<td>' . $limit->limit . '</td>';
        echo '<td>' . $limit->error . '</td>';
        echo '<td>' . ($limit->active ? 'Yes' : 'No') . '</td>';
        echo '<td>
            <a href="' . $modulelink . '&action=edit&id=' . $limit->id . '" class="btn btn-default btn-sm">Edit</a>
            <a href="' . $modulelink . '&action=delete&id=' . $limit->id . '" onclick="return confirm(\'Are you sure?\')" class="btn btn-danger btn-sm">Delete</a>
        </td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Add/edit form
    echo '<hr>';
    echo '<h2>' . ($editLimit ? 'Edit Limit' : 'Add New Limitation') . '</h2>';
    echo '<form method="post" action="' . $modulelink . '&action=' . ($editLimit ? 'editlimit' : 'addlimit') . '">';
    if ($editLimit) {
        echo '<input type="hidden" name="id" value="' . $editLimit->id . '">';
    }

    echo '<select name="product_id">';
    echo '<option value="">Select Product</option>';
    foreach ($products as $id => $name) {
        $selected = ($editLimit && $editLimit->product_id == $id) ? ' selected' : '';
        echo '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($name) . '</option>';
    }
    echo '</select><br><br>';
    echo 'Limit: <input type="number" name="limit" min="1" value="' . ($editLimit->limit ?? '') . '"><br><br>';
    echo 'Error Message: <input type="text" name="error" value="' . ($editLimit->error ?? '') . '"><br><br>';
    $activeChecked = ($editLimit && $editLimit->active) ? ' checked' : '';
    echo '<label><input type="checkbox" name="active" value="1"' . $activeChecked . '> Active</label><br><br>';
    echo '<input type="submit" value="' . ($editLimit ? 'Update Limit' : 'Add Limit') . '" class="btn btn-primary">';
    echo '</form>';
}
require_once __DIR__ . '/hooks.php';
