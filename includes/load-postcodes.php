<?php
// Load postcode areas from JSON
function load_postcode_areas_from_json() {
    $json_path = plugin_dir_path(__FILE__) . 'postcodes.json'; 
    if (file_exists($json_path)) {
        $json_contents = file_get_contents($json_path);
        $postcode_areas = json_decode($json_contents, true);
        return $postcode_areas;
    }
    return []; // Return an empty array if the file doesn't exist
}
