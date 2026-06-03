<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_scaffold_template_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wpcli_scaffold_template_autoloader ) ) {
	require_once $wpcli_scaffold_template_autoloader;
}

WP_CLI::add_command( 'scaffold template', 'Camaleaun_Scaffold_Template_Command' );
