<?php

namespace EasyWPSMTP;

use EasyWPSMTP\Admin\Area;
use EasyWPSMTP\Admin\DebugEvents\DebugEvents;
use EasyWPSMTP\Admin\DebugEvents\Migration as DebugMigration;
use EasyWPSMTP\Migrations\GeneralMigration;
use EasyWPSMTP\Queue\Migration as QueueMigration;
use EasyWPSMTP\Queue\Queue;
use EasyWPSMTP\Tasks\Meta;

/**
 * Class DBRepair to fix the DB related issues.
 *
 * @since 2.1.0
 */
class DBRepair {

	/**
	 * Hook all the functionality.
	 *
	 * @since 2.1.0
	 */
	public function hooks() {

		add_action( 'admin_init', [ $this, 'fix_missing_db_tables' ] );
		add_action( 'admin_init', [ $this, 'verify_db_tables_after_fixing' ] );
	}

	/**
	 * Fixed the missing tables.
	 *
	 * @since 2.1.0
	 */
	public function fix_missing_db_tables() { // phpcs:ignore Generic.Metrics.NestingLevel.MaxExceeded

		// Check if this is the request to create missing tables.
		if (
			isset( $_GET['create-missing-db-tables'] ) &&
			$_GET['create-missing-db-tables'] === '1' &&
			easy_wp_smtp()->get_admin()->is_admin_page() &&
			current_user_can( easy_wp_smtp()->get_capability_manage_options() )
		) {
			check_admin_referer( Area::SLUG . '-create-missing-db-tables' );

			$missing_tables = $this->get_missing_tables();

			if ( ! empty( $missing_tables ) ) {
				foreach ( $missing_tables as $missing_table ) {
					$this->fix_missing_db_table( $missing_table );
				}

				$redirect_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : Area::SLUG;
				$redirect_tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
				$query_args    = [
					'check-db-tables' => 1,
				];

				if ( ! empty( $redirect_tab ) ) {
					$query_args['tab'] = $redirect_tab;
				}

				$redirect_url = add_query_arg(
					$query_args,
					easy_wp_smtp()->get_admin()->get_admin_page_url( $redirect_page )
				);

				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
	}

	/**
	 * Update the Migration option to fix the missing table.
	 *
	 * @since 2.1.0
	 *
	 * @param string $missing_table The name of the table.
	 */
	protected function fix_missing_db_table( $missing_table ) {

		if ( $missing_table === DebugEvents::get_table_name() ) {
			update_option( DebugMigration::OPTION_NAME, 0 );
		} elseif ( $missing_table === Meta::get_table_name() ) {
			update_option( GeneralMigration::OPTION_NAME, 0 );
		} elseif ( $missing_table === Queue::get_table_name() ) {
			update_option( QueueMigration::OPTION_NAME, 0 );
		}
	}

	/**
	 * Default Unknown error message - If the table is not created.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	protected function get_missing_table_default_error_message() {

		$unknown_reason_msg = esc_html__( 'Unknown.', 'easy-wp-smtp' );

		/**
		 * Filter the default error message for unknown reason.
		 *
		 * @since 2.1.0
		 *
		 * @param string $unknown_reason_msg The default unknown reason message.
		 */
		return apply_filters( 'easy_wp_smtp_db_repair_get_missing_table_default_error_message', $unknown_reason_msg );
	}

	/**
	 * Get the error message (Reason) if the table is missing.
	 *
	 * @since 2.1.0
	 *
	 * @param string $missing_table The table name that we are checking.
	 * @param array  $reasons       The array that holds all the error messages or reason.
	 */
	protected function get_error_message_for_missing_table( $missing_table, &$reasons ) {

		$reason = '';

		if ( $missing_table === DebugEvents::get_table_name() ) {
			$reason .= $this->get_reason_output_message(
				$missing_table,
				get_option( DebugMigration::ERROR_OPTION_NAME, $this->get_missing_table_default_error_message() )
			);
		} elseif ( $missing_table === Meta::get_table_name() ) {
			$reason .= $this->get_reason_output_message(
				$missing_table,
				get_option( GeneralMigration::ERROR_OPTION_NAME, $this->get_missing_table_default_error_message() )
			);
		} elseif ( $missing_table === Queue::get_table_name() ) {
			$reason .= $this->get_reason_output_message(
				$missing_table,
				get_option( QueueMigration::ERROR_OPTION_NAME, $this->get_missing_table_default_error_message() )
			);
		}

		$reasons[] = $reason;
	}

	/**
	 * Get the reason output message, why the DB table creation failed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $table         The DB table name.
	 * @param string $error_message The error message.
	 *
	 * @return string
	 */
	protected function get_reason_output_message( $table, $error_message ) {

		return sprintf(
			wp_kses( /* translators: %1$s - missing table name; %2$s - error message. */
				__( '<strong>Table:</strong> %1$s. <strong>Reason:</strong> %2$s', 'easy-wp-smtp' ),
				[
					'strong' => [],
				]
			),
			esc_html( $table ),
			esc_html( $error_message )
		);
	}

	/**
	 * Verify the tables.
	 * If there is any missing table then display the Admin Notice of error type.
	 * Else display the success message (Success Admin Notice).
	 *
	 * @since 2.1.0
	 */
	public function verify_db_tables_after_fixing() {

		// Display success or error message based on if there is any missing table available or not.
		if (
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_GET['check-db-tables'] ) && $_GET['check-db-tables'] === '1' &&
			easy_wp_smtp()->get_admin()->is_admin_page() &&
			current_user_can( easy_wp_smtp()->get_capability_manage_options() )
		) {
			$missing_tables = $this->get_missing_tables();

			if ( empty( $missing_tables ) ) {
				WP::add_admin_notice(
					esc_html__( 'Missing DB tables were created successfully.', 'easy-wp-smtp' ),
					WP::ADMIN_NOTICE_SUCCESS
				);

				return;
			}

			$reasons = [];

			foreach ( $missing_tables as $missing_table ) {
				$this->get_error_message_for_missing_table( $missing_table, $reasons );
			}

			$reasons = array_filter( $reasons ); // Filtering out the empty values.

			if ( ! empty( $reasons ) ) {
				$msg = sprintf(
					wp_kses( /* translators: %1$s: Singular/Plural string, %2$s - the error messages from the migrations for the missing tables. */
						__( 'The following DB %1$s still missing. <br />%2$s', 'easy-wp-smtp' ),
						[
							'br' => [],
						]
					),
					_n( 'Table is', 'Tables are', count( $missing_tables ), 'easy-wp-smtp' ),
					implode( '<br/>', $reasons )
				);
			} else {
				$msg = esc_html__( 'Some DB Tables are still missing.', 'easy-wp-smtp' );
			}

			WP::add_admin_notice(
				$msg,
				WP::ADMIN_NOTICE_ERROR
			);
		}
	}

	/**
	 * Get the missing tables.
	 *
	 * @since 2.1.0
	 *
	 * @return array The array of the missing tables.
	 */
	protected function get_missing_tables() {

		$site_health = new SiteHealth();

		return $site_health->get_missing_db_tables();
	}
}
