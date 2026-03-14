<?php

/**
 * Main app class for WHMCS. This is used for type hinting and code completion in IDEs.
 * 
 * @method static string getSystemUrl(bool $trail = true) Get the system URL with optional trailing slash.
 * @method static string get_admin_folder_name() Get the name of the admin folder.
 */
class App extends WHMCS\Application {}