<?php

// Quotes, sent revisions, PDFs and history are business records. Deleting the plugin keeps them.
// Remove the wewp_aq_* tables and options manually after you export what you need.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
