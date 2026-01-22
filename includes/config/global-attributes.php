<?php
/**
 * Global attributes source of truth.
 *
 * IMPORTANT:
 * - Keep this data in sync with your configured product types, colors, and sizes.
 * - This file is the only source for global attributes (no DB storage).
 */

return array(
    'products' => array(
        // Example structure:
        // array(
        //     'key' => 'polo',
        //     'label' => 'Póló',
        //     'price' => 0,
        //     'sizes' => array('XS', 'S', 'M', 'L', 'XL'),
        //     'colors' => array(
        //         array('slug' => 'feher', 'name' => 'Fehér', 'hex' => '#FFFFFF', 'surcharge' => 0),
        //         array('slug' => 'fekete', 'name' => 'Fekete', 'hex' => '#000000', 'surcharge' => 0),
        //     ),
        //     'primary_color' => 'feher',
        //     'primary_size' => 'M',
        //     'size_color_matrix' => array(
        //         'XS' => array('feher', 'fekete'),
        //         'S' => array('feher', 'fekete'),
        //     ),
        //     'size_surcharges' => array(
        //         'XL' => 200,
        //     ),
        //     'type_description' => '',
        // ),
    ),
);
