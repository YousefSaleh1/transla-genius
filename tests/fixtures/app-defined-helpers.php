<?php

// Loaded with auto_prepend_file, before Composer's autoloader: an app that
// defines its own functions with the package's helper names.

define('TRANSLAGENIUS_APP_HELPERS', true);

function get_current_locale()
{
    return 'app-current-locale';
}

function get_supported_languages(?string $excludeLang = null)
{
    return ['app-language'];
}
