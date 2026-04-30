<?php

/**
 * Per-portal configuration for the "maravalroad" portal group.
 *
 * Stored as a PHP file (returning an array) instead of JSON because the
 * front-end nginx layer (HestiaCP-managed) serves *.json files directly
 * from disk, bypassing Apache and the project's .htaccess. PHP files are
 * forwarded to PHP-FPM and never exposed as raw text. As an additional
 * safety net, the project root .htaccess returns 403 for any /config/...
 * URL.
 *
 * Copy this file to maravalroad.php and fill in real values; the real
 * file is gitignored. Anything that varies per portal lives here, not
 * in the database.
 */

return [
    'laundry' => [
        'enabled' => true,
        'price_cents' => 500,
        'currency' => 'USD',
        // Days a guest can revisit /laundry after payment to see the
        // combination again before being asked to pay once more. Optional;
        // default is 7. Clamped to [1, 365] in code.
        'access_days' => 7,
        'padlock_combination' => 'REPLACE-ME',
        'padlock_instructions_html' => '<p>Replace this with HTML instructions for unlocking and using the laundry room. The padlock combination above and these instructions are revealed only after a successful PayPal payment.</p>',
    ],
    'supplies' => [
        'enabled' => true,
        'item_suggestions' => [
            'Bath towels',
            'Hand towels',
            'Bath mats',
            'Bed sheets',
            'Pillowcases',
            'Toilet paper',
            'Paper towels',
            'Hand soap',
            'Dish soap',
            'Trash bags',
            'Coffee',
        ],
    ],
];
