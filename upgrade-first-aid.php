<?php

/*
Plugin Name: Upgrade First Aid
Plugin URI: https://github.com/daveross/upgrade-first-aid
Description: Troubleshoot automatic upgrade issues
Author: David Michael Ross
Version: 1.0
Author URI: http://davidmichaelross.com
License: MIT
Text Domain: upgrade_first_aid
*/

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once dirname( __FILE__ ) . '/UpgradeFirstAidUtil.inc';

add_action( 'admin_menu', array( 'UpgradeFirstAid', 'admin_menu' ) );
add_action( 'admin_init', array( 'UpgradeFirstAid', 'admin_init' ) );
add_action( 'admin_enqueue_scripts', array( 'UpgradeFirstAid', 'admin_enqueue_scripts' ) );

/**
 * Main functionality for the Upgrade First Aid plugin
 */
class UpgradeFirstAid {

	public static function admin_menu() {
		add_management_page( "Upgrade First Aid", __( 'Upgrade First Aid', 'upgrade_first_aid' ), 'manage_options', __FILE__, array( 'UpgradeFirstAid', 'plugin_options' ) );
	}

	public static function admin_init() {
		add_meta_box( 'upgrade_first_aid_resources', __( 'Resources', 'upgrade_first_aid' ), array( 'UpgradeFirstAid', 'meta_box_resources' ), 'upgrade_first_aid', 'normal', 'high' );
	}

	public static function admin_enqueue_scripts() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'jquery-ui-draggable' );
	}

	public static function plugin_options() {

		if ( !class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$screen = get_current_screen();

		echo '<style>code {white-space: nowrap;}.details{width: 68%;min-width: 300px;float:left;margin-right:2%;}.sidebar{width:29%;min-width:200px;float:left;}</style>';
		echo "<div class=\"wrap\">";
		screen_icon();
		echo '<h2>' . __( 'Upgrade First Aid', 'upgrade_first_aid' ) . '</h2>';

		echo '<div class="details">';
		echo '<h3>' . __( 'Core Upgrades', 'upgrade_first_aid' ) . '</h3>';
		$type = get_filesystem_method( array(), ABSPATH );
		$method = UpgradeFirstAidUtil::upgrade_method_description( $type );
		echo '<p>' . UpgradeFirstAidUtil::type_icon( $type ) . ' ' . sprintf( 'WordPress can install core upgrades %s.', $method ) . '</p>';
		self::maybe_ownership_mismatch( ABSPATH . 'index.php' );

		echo '<h3>' . __( 'Plugin Upgrades' , 'upgrade_first_aid' ) . '</h3>';
		$type = get_filesystem_method( array(), WP_PLUGIN_DIR );
		$method = UpgradeFirstAidUtil::upgrade_method_description( $type );
		echo '<p>' . UpgradeFirstAidUtil::type_icon( $type ) . sprintf( 'WordPress can install plugins %s.', $method ) . '</p>';
		self::maybe_ownership_mismatch( WP_PLUGIN_DIR );

		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_path => $plugin_details ) {
			$plugin_full_path = dirname( WP_PLUGIN_DIR . "/{$plugin_path}" );
			$type = get_filesystem_method( array(), WP_PLUGIN_DIR );
			$method = UpgradeFirstAidUtil::upgrade_method_description( $type );
			echo '<p>' . UpgradeFirstAidUtil::type_icon( $type ) . sprintf( 'WordPress can upgrade the %s plugin %s.', $plugin_details['Name'], $method ) . '</p>';
			self::maybe_ownership_mismatch( $plugin_full_path, $plugin_details['Name'] );
		}

		echo '<h3>' . __( 'Theme Upgrades', 'upgrade_first_aid' ) . '</h3>';
		$type = get_filesystem_method( array(), get_theme_root() );
		$method = UpgradeFirstAidUtil::upgrade_method_description( $type );
		echo '<p>' . UpgradeFirstAidUtil::type_icon( $type ) . sprintf( 'WordPress can install themes %s.', $method ) . '</p>';
		self::maybe_ownership_mismatch( get_theme_root() );

		set_error_handler( array( __CLASS__, 'error_handler' ), E_ALL );
		$all_themes = wp_get_themes();
		restore_error_handler();

		foreach ( $all_themes as $theme_path => $theme_details ) {
			$theme_full_path = $theme_details->get_stylesheet_directory();
			$type = get_filesystem_method( array(), $theme_full_path );
			$method = UpgradeFirstAidUtil::upgrade_method_description( $type );
			echo '<p>' . UpgradeFirstAidUtil::type_icon( $type ) . sprintf( 'WordPress can upgrade the %s theme %s.', $theme_details->Name, $method ) . '</p>';
			self::maybe_ownership_mismatch( get_theme_root() );
		}

		echo '</div>';

		echo '<div class="sidebar">';
		do_meta_boxes( 'upgrade_first_aid', 'normal', new stdClass() );
		echo '</div>';
		echo '</div>';
	}

	private static function maybe_ownership_mismatch( $context, $item = "WordPress" ) {
		$php_user = UpgradeFirstAidUtil::current_php_user();
		$wp_owner = WP_Filesystem_Direct::owner( $context );

		if ( preg_match( '/index.php$/', $context ) ) {
			$directory = dirname( $context );
		}
		else {
			$directory = $context;
		}

		if ( $php_user !== $wp_owner ) {
			echo '<p>' . UpgradeFirstAidUtil::img_tag( admin_url( 'images/no.png' ) ) . sprintf( __( "PHP is currently running as the user <em>%s</em>, but the %s files are owned by the user <em>%s</em>. WordPress could install upgrades without FTP if you changed the files' owner to <em>%s</em>." ), $php_user, $item, $wp_owner, $php_user ) . '</li>';
			if ( UpgradeFirstAidUtil::can_write_to_directory( $directory ) && !defined( 'FS_METHOD' ) ) {
				echo '<p>' . UpgradeFirstAidUtil::img_tag( admin_url( 'images/comment-grey-bubble.png' ) ) . sprintf( __( "<em>%s</em> can write to the %s directory, so you can try adding <code>%s</code> to your wp-config.php file which might allow upgrades without FTP.", 'upgrade_first_aid' ), $php_user, $directory, "define('FS_METHOD', 'direct');" ) . '</p>';
			}
		}
	}

	public static function meta_box_resources() {
		echo '<ul>';
		_e( sprintf( 'The WordPress documentation has instructions on <a href="%s">how file permissions work</a>, as well as notes on <a href="%s">what permissions WordPress needs</a>.', 'http://codex.wordpress.org/Changing_File_Permissions', 'http://codex.wordpress.org/Changing_File_Permissions#Permission_Scheme_for_WordPress' ), 'upgrade_first_aid' );
		echo '</ul>';
	}

	public static function error_handler( $errno , $errstr,  $errfile , $errline ,  $errcontext ) {
		print "$errno $errstr $errfile $errline";
		print "<hr>";
		print_r($errcontext);
	}
}