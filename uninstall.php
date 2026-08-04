<?php
/**
 * Uninstall cleanup for NoorDev Migrate.
 *
 * Removes options and temp files. Staging/backup TABLES are intentionally
 * left in place — dropping database tables on uninstall could destroy the
 * only rollback copy of a site.
 *
 * @package NoorDev_Migrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ndm_secret' );
delete_option( 'ndm_connection' );
delete_option( 'ndm_settings' );
delete_option( 'ndm_source_state' );
delete_option( 'ndm_dest_state' );
delete_option( 'ndm_dest_manifest' );
delete_option( 'ndm_log' );

wp_clear_scheduled_hook( 'ndm_cron_tick' );

// Temp/staging files.
foreach ( array( WP_CONTENT_DIR . '/ndm-tmp', WP_CONTENT_DIR . '/ndm-staging' ) as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
