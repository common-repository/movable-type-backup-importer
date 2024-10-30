<?php

/*
Plugin Name: Movable Type Backup Importer
Plugin URI: http://wordpress.org/extend/plugins/movabletype-backup-importer/
Description: Import Backups from Movable Type
Version: 1.1.2
Author: Roger Dudler
Author URI: http://www.rogerdudler.com
License: GPL
*/

if (!defined('WP_LOAD_IMPORTERS'))
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';
if (!class_exists( 'WP_Importer')) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if (file_exists( $class_wp_importer ))
		require_once $class_wp_importer;
}

// Define constants
define('PLUGIN_ID', 'movable-type-backup-importer');

// Load Importer library and register importer
require_once 'class-mt-backup-import.php';
register_importer(
	PLUGIN_ID, 
	__('Movable Type (Backup)', PLUGIN_ID), 
	__('Import a Backup from Movable Type.', PLUGIN_ID), 
	array(new MT_Backup_Import(), 'dispatch')
);