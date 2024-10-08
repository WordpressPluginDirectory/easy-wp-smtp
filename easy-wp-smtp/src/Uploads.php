<?php

namespace EasyWPSMTP;

use WP_Error;
use WP_Filesystem_Direct;

/**
 * EasyWPSMTP uploads.
 *
 * @since 2.1.0
 */
class Uploads {

	/**
	 * Get EasyWPSMTP upload root path (e.g. /wp-content/uploads/easy-wp-smtp).
	 *
	 * @since 2.1.0
	 *
	 * @return array|WP_Error EasyWPSMTP upload root path (no trailing slash).
	 */
	public static function upload_dir() {

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'wp_upload_dir_error', $upload_dir['error'] );
		}

		$dir = 'easy-wp-smtp';

		$upload_root = trailingslashit( realpath( $upload_dir['basedir'] ) ) . $dir;

		/**
		 * Filters upload dir path.
		 *
		 * @since 2.1.0
		 *
		 * @param string $upload_root Upload dir path.
		 */
		$custom_uploads_root = apply_filters( 'easy_wp_smtp_uploads_upload_dir_root', $upload_root );
		if ( wp_is_writable( $custom_uploads_root ) ) {
			$upload_root = $custom_uploads_root;
		}

		if ( ! file_exists( $upload_root ) && ! wp_mkdir_p( $custom_uploads_root ) ) {
			return new WP_Error(
				'easy_wp_smtp_upload_dir_unable_create',
				sprintf(
				/* translators: %s: Directory path. */
					__( 'Unable to create directory %s. Is its parent directory writable by the server?', 'easy-wp-smtp' ),
					esc_html( $upload_root )
				)
			);
		}

		if ( ! wp_is_writable( $custom_uploads_root ) ) {
			return new WP_Error(
				'easy_wp_smtp_upload_dir_not_writable',
				sprintf(
				/* translators: %s: Directory path. */
					__( 'Unable to write in EasyWPSMTP upload directory %s. Is it writable by the server?', 'easy-wp-smtp' ),
					esc_html( $upload_root )
				)
			);
		}

		return [
			'path' => $upload_root,
			'url'  => trailingslashit( $upload_dir['baseurl'] ) . $dir,
		];
	}

	/**
	 * Create .htaccess file in the EasyWPSMTP upload directory.
	 *
	 * @since 2.1.0
	 *
	 * @return bool True when the .htaccess file exists, false on failure.
	 */
	public static function create_upload_dir_htaccess_file() {

		/**
		 * Filters create upload dir htaccess file.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $is_create Creates upload dir htaccess file.
		 */
		if ( ! apply_filters( 'easy_wp_smtp_uploads_create_upload_dir_htaccess_file', true ) ) {
			return false;
		}

		$upload_dir = self::upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return false;
		}

		$htaccess_file = wp_normalize_path( trailingslashit( $upload_dir['path'] ) . '.htaccess' );
		$cache_key     = 'easy_wp_smtp_upload_dir_htaccess_file';

		if ( is_file( $htaccess_file ) ) {
			$cached_stat = get_transient( $cache_key );
			$stat        = array_intersect_key(
				stat( $htaccess_file ),
				[
					'size'  => 0,
					'mtime' => 0,
					'ctime' => 0,
				]
			);

			if ( $cached_stat === $stat ) {
				return true;
			}

			@unlink( $htaccess_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		/**
		 * Filters upload dir htaccess file content.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $content Upload dir htaccess file content.
		 */
		$contents = apply_filters(
			'easy_wp_smtp_uploads_create_upload_dir_htaccess_file_content',
			'# Disable PHP and Python scripts parsing.
<Files *>
  SetHandler none
  SetHandler default-handler
  RemoveHandler .cgi .php .php3 .php4 .php5 .phtml .pl .py .pyc .pyo
  RemoveType .cgi .php .php3 .php4 .php5 .phtml .pl .py .pyc .pyo
</Files>
<IfModule mod_php5.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>
<IfModule mod_php8.c>
  php_flag engine off
</IfModule>
<IfModule headers_module>
  Header set X-Robots-Tag "noindex"
</IfModule>'
		);

		$created = insert_with_markers( $htaccess_file, 'EasyWPSMTP', $contents );

		if ( $created ) {
			clearstatcache( true, $htaccess_file );
			$stat = array_intersect_key(
				stat( $htaccess_file ),
				[
					'size'  => 0,
					'mtime' => 0,
					'ctime' => 0,
				]
			);

			set_transient( $cache_key, $stat );
		}

		return $created;
	}

	/**
	 * Create index.html file in the specified directory if it doesn't exist.
	 *
	 * @since 2.1.0
	 *
	 * @param string $path Path to the directory.
	 *
	 * @return int|false Number of bytes that were written to the file, or false on failure.
	 */
	public static function create_index_html_file( $path ) {

		if ( ! is_dir( $path ) || is_link( $path ) ) {
			return false;
		}

		$index_file = wp_normalize_path( trailingslashit( $path ) . 'index.html' );

		// Do nothing if index.html exists in the directory.
		if ( file_exists( $index_file ) ) {
			return false;
		}

		// Create empty index.html.
		return file_put_contents( $index_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Delete the Easy WP SMTP uploads directory.
	 *
	 * @since 2.6.0
	 *
	 * @return void
	 */
	public static function delete_upload_dir() {

		// Get the upload dir.
		$upload_dir = self::upload_dir();

		// If there is an error, return.
		if ( is_wp_error( $upload_dir ) ) {
			return;
		}

		$upload_root = $upload_dir['path'];

		// Get WP Filesystem base files.
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		// Initialize WP_Filesystem_Direct.
		$wp_filesystem = new WP_Filesystem_Direct( false );

		// Delete the directory.
		$wp_filesystem->delete( $upload_root, true );
	}
}
