<?php
/**
 * Settings screen.
 *
 * The Cloudflare token is a credential, so it is write-only in the UI: the
 * stored value is never rendered back into the form, only its presence is
 * reported. Submitting an empty field leaves the existing token untouched,
 * which is what makes the rest of the form safe to re-save.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Admin;

use PageBuilderCacheControl\Cloudflare\Api;
use PageBuilderCacheControl\Providers\Registry;
use PageBuilderCacheControl\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	private const SLUG  = 'wp-page-builder-cache-control';
	private const NONCE = 'pbcc_settings_save';

	/**
	 * @var array<int,array{type:string,text:string}>
	 */
	private array $notices = array();

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PBCC_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_page(): void {
		$this->hook = (string) add_options_page(
			__( 'Cache Control', 'wp-page-builder-cache-control' ),
			__( 'Cache Control', 'wp-page-builder-cache-control' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Settings link on the plugins list row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();

		if ( ! current_user_can( $this->capability() ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'wp-page-builder-cache-control' )
			)
		);

		return $links;
	}

	/**
	 * `admin_menu` runs before `admin_enqueue_scripts`, so the hook suffix is
	 * always set by the time this fires.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_script(
			'pbcc-settings',
			PBCC_URL . 'assets/js/settings.js',
			array(),
			PBCC_VERSION,
			true
		);
	}

	private function capability(): string {
		return (string) apply_filters( 'pbcc/capability', 'manage_options' );
	}

	public function maybe_save(): void {
		if ( ! isset( $_POST['pbcc_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['pbcc_action'] ) );

		if ( 'test' === $action ) {
			$this->run_test();
			return;
		}

		$values = array();

		// Empty means "leave the stored token alone" - not "clear it".
		if ( ! Settings::cf_token_locked() ) {
			$submitted = isset( $_POST['cf_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cf_token'] ) ) ) : '';

			if ( ! empty( $_POST['cf_token_remove'] ) ) {
				$values['cf_token'] = '';
				Api::forget_zone();
			} elseif ( '' !== $submitted ) {
				$values['cf_token'] = $submitted;
				Api::forget_zone();
			}
		}

		if ( ! Settings::cf_zone_locked() ) {
			$zone = isset( $_POST['cf_zone_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cf_zone_id'] ) ) ) : '';

			if ( $zone !== Settings::cf_zone_id() ) {
				Api::forget_zone();
			}

			$values['cf_zone_id'] = $zone;
		}

		if ( ! Settings::cf_auto_purge_locked() ) {
			$values['cf_auto_purge'] = ! empty( $_POST['cf_auto_purge'] );
		}

		if ( ! Settings::admin_bar_locked() ) {
			$values['admin_bar'] = ! empty( $_POST['admin_bar'] );
		}

		if ( ! Settings::adopt_menus_locked() ) {
			$menu_on = array_key_exists( 'admin_bar', $values )
				? (bool) $values['admin_bar']
				: Settings::admin_bar();

			// Turning the menu off turns consolidation off with it. A disabled
			// checkbox submits nothing, so this is also what makes the two
			// fields agree without trusting the browser to enforce it.
			$values['adopt_menus'] = $menu_on && ! empty( $_POST['adopt_menus'] );
		}

		$lifetime = isset( $_POST['preview_lifetime'] ) ? absint( wp_unslash( $_POST['preview_lifetime'] ) ) : 0;

		if ( $lifetime > 0 ) {
			$values['preview_lifetime'] = max( 300, min( $lifetime * MINUTE_IN_SECONDS, DAY_IN_SECONDS ) );
		}

		Settings::update( $values );

		$this->notices[] = array(
			'type' => 'success',
			'text' => __( 'Settings saved.', 'wp-page-builder-cache-control' ),
		);
	}

	/**
	 * Prove the token works and say which zone it resolved to - the two things
	 * you actually want to know before trusting the button.
	 */
	private function run_test(): void {
		if ( ! Api::has_token() ) {
			$this->notices[] = array(
				'type' => 'error',
				'text' => __( 'No Cloudflare API token is set yet.', 'wp-page-builder-cache-control' ),
			);
			return;
		}

		Api::forget_zone();

		$api  = new Api();
		$zone = $api->zone_id();

		if ( '' === $zone ) {
			$error = $api->last_error_message();

			$this->notices[] = array(
				'type' => 'error',
				'text' => $error !== '' ? $error : __( 'No Cloudflare zone matches this domain.', 'wp-page-builder-cache-control' ),
			);
			return;
		}

		$this->notices[] = array(
			'type' => 'success',
			'text' => sprintf(
				/* translators: 1: domain, 2: Cloudflare zone id. */
				__( 'Connected. %1$s resolved to zone %2$s.', 'wp-page-builder-cache-control' ),
				esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				esc_html( $zone )
			),
		);
	}

	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cache Control', 'wp-page-builder-cache-control' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Controls in your builder', 'wp-page-builder-cache-control' ); ?></h2>
			<table class="widefat striped" style="max-width:52em">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cache', 'wp-page-builder-cache-control' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-page-builder-cache-control' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Registry::all() as $provider ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $provider->label() ); ?></strong></td>
						<td>
							<?php if ( $provider->is_available() ) : ?>
								<span style="color:#00722f">&#10003; <?php esc_html_e( 'Active - button shown', 'wp-page-builder-cache-control' ); ?></span>
							<?php else : ?>
								<span style="color:#8c8f94"><?php esc_html_e( 'Not configured - button hidden', 'wp-page-builder-cache-control' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php
				/*
				 * Submitting with Enter from a text field sends no submitter
				 * button, so without this the request carries no pbcc_action and
				 * the save silently does nothing. A button used as the submitter
				 * appears later in the form and therefore wins in PHP.
				 */
				?>
				<input type="hidden" name="pbcc_action" value="save">

				<h2><?php esc_html_e( 'Cloudflare', 'wp-page-builder-cache-control' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pbcc_cf_token"><?php esc_html_e( 'API token', 'wp-page-builder-cache-control' ); ?></label>
						</th>
						<td>
							<?php if ( Settings::cf_token_locked() ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php. Remove PBCC_CF_TOKEN to manage it here.', 'wp-page-builder-cache-control' ); ?></em></p>
							<?php else : ?>
								<input type="password" id="pbcc_cf_token" name="cf_token" value=""
									autocomplete="new-password" class="regular-text"
									placeholder="<?php echo Settings::cf_token() !== '' ? esc_attr__( 'Stored - leave blank to keep', 'wp-page-builder-cache-control' ) : ''; ?>">
								<?php if ( Settings::cf_token() !== '' ) : ?>
									<p><label><input type="checkbox" name="cf_token_remove" value="1"> <?php esc_html_e( 'Remove the stored token', 'wp-page-builder-cache-control' ); ?></label></p>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Scope the token to Zone > Cache Purge > Purge only. Do not reuse a token that also manages firewall rules, zone settings or R2.', 'wp-page-builder-cache-control' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pbcc_cf_zone_id"><?php esc_html_e( 'Zone ID', 'wp-page-builder-cache-control' ); ?></label>
						</th>
						<td>
							<?php if ( Settings::cf_zone_locked() ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php.', 'wp-page-builder-cache-control' ); ?></em></p>
							<?php else : ?>
								<input type="text" id="pbcc_cf_zone_id" name="cf_zone_id"
									value="<?php echo esc_attr( Settings::cf_zone_id() ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Optional. Left blank, the zone is discovered from this site\'s domain and cached for a week.', 'wp-page-builder-cache-control' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-purge', 'wp-page-builder-cache-control' ); ?></th>
						<td>
							<?php if ( Settings::cf_auto_purge_locked() ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php.', 'wp-page-builder-cache-control' ); ?></em></p>
							<?php else : ?>
								<label>
									<input type="checkbox" name="cf_auto_purge" value="1" <?php checked( Settings::cf_auto_purge() ); ?>>
									<?php esc_html_e( 'Purge Cloudflare when a page is saved', 'wp-page-builder-cache-control' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Purges the saved page and the front page, not the whole zone. Covers builder saves as well as the block editor.', 'wp-page-builder-cache-control' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin bar menu', 'wp-page-builder-cache-control' ); ?></th>
						<td>
							<?php if ( Settings::admin_bar_locked() ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php.', 'wp-page-builder-cache-control' ); ?></em></p>
							<?php else : ?>
								<label>
									<input type="checkbox" name="admin_bar" value="1" <?php checked( Settings::admin_bar() ); ?>>
									<?php esc_html_e( 'Add a Cache Control menu to the WordPress admin bar', 'wp-page-builder-cache-control' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Adds Purge Cloudflare Cache and Copy Uncached Preview Link. Each item appears only when it can do its job.', 'wp-page-builder-cache-control' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Consolidate menus', 'wp-page-builder-cache-control' ); ?></th>
						<td>
							<?php if ( Settings::adopt_menus_locked() ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php.', 'wp-page-builder-cache-control' ); ?></em></p>
							<?php else : ?>
								<label>
									<input type="checkbox" name="adopt_menus" value="1"
										<?php checked( Settings::adopt_menus() ); ?>
										<?php disabled( ! Settings::admin_bar() ); ?>>
									<?php esc_html_e( 'Move the Nginx Helper and Redis Object Cache menus into it', 'wp-page-builder-cache-control' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Those plugins keep working exactly as before - their menus are only re-parented, and return to the top level if you switch this off. Needs the menu above.', 'wp-page-builder-cache-control' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Uncached preview', 'wp-page-builder-cache-control' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pbcc_preview_lifetime"><?php esc_html_e( 'Preview window', 'wp-page-builder-cache-control' ); ?></label>
						</th>
						<td>
							<input type="number" id="pbcc_preview_lifetime" name="preview_lifetime" min="5" max="1440" step="5"
								value="<?php echo esc_attr( (string) round( Settings::preview_lifetime() / MINUTE_IN_SECONDS ) ); ?>" class="small-text">
							<?php esc_html_e( 'minutes', 'wp-page-builder-cache-control' ); ?>
							<p class="description"><?php esc_html_e( 'How long a browser opened with a preview link keeps reading past the page cache.', 'wp-page-builder-cache-control' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="pbcc_action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save Changes', 'wp-page-builder-cache-control' ); ?>
					</button>
					<button type="submit" name="pbcc_action" value="test" class="button">
						<?php esc_html_e( 'Test Cloudflare connection', 'wp-page-builder-cache-control' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
