<?php
/**
 * Settings page.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Ruboja Assistants: API key, released assistants, display and privacy.
 */
final class Admin_Page {

	const SLUG          = 'ruboja-assistants';
	const CAPABILITY    = 'manage_options';
	const ACTION_SAVE   = 'ruboja_assistants_save';
	const ACTION_SYNC   = 'ruboja_assistants_sync';
	const NOTICE_PREFIX = 'ruboja_assistants_notice_';

	/**
	 * Ruboja mark (logos/ruboja-mark.svg), shown next to the page title.
	 */
	const MARK_SVG = '<svg class="ruboja-assistants-admin__mark" viewBox="0 0 100 100" width="44" height="44" fill="none" aria-hidden="true" focusable="false"><circle cx="50" cy="50" r="32" stroke="#df252b" stroke-opacity="0.22" stroke-width="2.5"/><g stroke="#df252b" stroke-width="3" stroke-opacity="0.5"><line x1="50" y1="50" x2="82" y2="50"/><line x1="50" y1="50" x2="66" y2="77.7"/><line x1="50" y1="50" x2="34" y2="77.7"/><line x1="50" y1="50" x2="18" y2="50"/><line x1="50" y1="50" x2="34" y2="22.3"/><line x1="50" y1="50" x2="66" y2="22.3"/></g><g fill="#df252b"><circle cx="82" cy="50" r="5"/><circle cx="66" cy="77.7" r="5"/><circle cx="34" cy="77.7" r="5"/><circle cx="18" cy="50" r="5"/><circle cx="34" cy="22.3" r="5"/><circle cx="66" cy="22.3" r="5"/></g><circle cx="50" cy="50" r="9" fill="#7a1619"/></svg>';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC, array( $this, 'handle_sync' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page below Settings.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Ruboja Assistants', 'ruboja-assistants' ),
			__( 'Ruboja Assistants', 'ruboja-assistants' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a "Settings" link to the plugin list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $this->page_url() ), esc_html__( 'Settings', 'ruboja-assistants' ) )
		);

		return $links;
	}

	/**
	 * Loads CSS and JS on the settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'ruboja-assistants-admin', plugins_url( 'assets/css/admin.css', PLUGIN_FILE ), array(), Embed::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'ruboja-assistants-admin', plugins_url( 'assets/js/admin.js', PLUGIN_FILE ), array(), Embed::asset_version( 'assets/js/admin.js' ), true );
		wp_add_inline_script(
			'ruboja-assistants-admin',
			'window.RubojaAssistantsAdmin = ' . wp_json_encode( array( 'copied' => __( 'Copied', 'ruboja-assistants' ) ) ) . ';',
			'before'
		);
	}

	/**
	 * Saves the settings form.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->authorize( self::ACTION_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$input = isset( $_POST['ruboja_assistants'] ) && is_array( $_POST['ruboja_assistants'] )
			? map_deep( wp_unslash( $_POST['ruboja_assistants'] ), 'sanitize_textarea_field' )
			: array();

		$new_key    = isset( $_POST['ruboja_assistants_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ruboja_assistants_api_key'] ) ) : '';
		$remove_key = ! empty( $_POST['ruboja_assistants_remove_key'] );
		$enabled    = $this->posted_ids( 'ruboja_assistants_enabled' );
		$logged_in  = $this->posted_ids( 'ruboja_assistants_logged_in_only' );
		// phpcs:enable

		Settings::save( $input );
		Settings::save_release_flags( $enabled, $logged_in );

		$key_changed = false;

		if ( ! Settings::api_key_from_constant() ) {
			if ( $remove_key ) {
				Settings::set_api_key( '' );
			} elseif ( '' !== $new_key ) {
				Settings::set_api_key( $new_key );
				$key_changed = true;
			}
		}

		if ( $key_changed ) {
			$this->sync_and_notify();
		} else {
			$this->notify( 'success', __( 'Settings saved.', 'ruboja-assistants' ) );
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Tests the connection and refreshes the assistant list.
	 *
	 * @return void
	 */
	public function handle_sync(): void {
		$this->authorize( self::ACTION_SYNC );
		$this->sync_and_notify();

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings   = Settings::all();
		$assistants = Settings::assistants();
		$released   = Settings::released_assistants();
		$last_sync  = Settings::last_sync();
		$sync_url   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_SYNC ), self::ACTION_SYNC );

		?>
		<div class="wrap ruboja-assistants-admin">
			<div class="ruboja-assistants-admin__head">
				<?php echo self::MARK_SVG; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?>
				<div>
					<h1><?php esc_html_e( 'Ruboja Assistants', 'ruboja-assistants' ); ?></h1>
					<?php $this->render_version(); ?>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<section class="ruboja-assistants-admin__card">
				<h2><?php esc_html_e( 'Connection', 'ruboja-assistants' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ruboja-assistants-api-key"><?php esc_html_e( 'API key', 'ruboja-assistants' ); ?></label></th>
						<td>
							<?php $this->render_api_key_field(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'ruboja-assistants' ); ?></th>
						<td>
							<p class="<?php echo esc_attr( 'ruboja-assistants-admin__status' . ( $last_sync > 0 ? ' is-connected' : '' ) ); ?>">
								<?php
								if ( $last_sync > 0 ) {
									printf(
										/* translators: 1: date and time, 2: number of assistants. */
										esc_html__( 'Last refreshed %1$s, %2$d assistants accessible.', 'ruboja-assistants' ),
										esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) ),
										count(
											array_filter(
												$assistants,
												static function ( $assistant ) {
													return empty( $assistant['missing'] );
												}
											)
										)
									);
								} else {
									esc_html_e( 'Not connected yet.', 'ruboja-assistants' );
								}
								?>
							</p>
							<p><a class="button" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Test connection and refresh list', 'ruboja-assistants' ); ?></a></p>
						</td>
					</tr>
				</table>
				</section>

				<section class="ruboja-assistants-admin__card">
				<h2><?php esc_html_e( 'Released assistants', 'ruboja-assistants' ); ?></h2>
				<p><?php esc_html_e( 'Only released assistants can be embedded. All other assistants of your organization stay private, even if someone knows their id.', 'ruboja-assistants' ); ?></p>
				<?php $this->render_assistant_table( $assistants ); ?>
				</section>

				<section class="ruboja-assistants-admin__card">
				<h2><?php esc_html_e( 'Privacy', 'ruboja-assistants' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Load on click', 'ruboja-assistants' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ruboja_assistants[consent_required]" value="1" <?php checked( $settings['consent_required'] ); ?>>
								<?php esc_html_e( 'Show a notice first and connect to Ruboja only after the visitor clicks "Start assistant" (recommended)', 'ruboja-assistants' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ruboja-assistants-consent-text"><?php esc_html_e( 'Notice text', 'ruboja-assistants' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="ruboja-assistants-consent-text" name="ruboja_assistants[consent_text]" placeholder="<?php echo esc_attr( Settings::consent_text() ); ?>"><?php echo esc_textarea( $settings['consent_text'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave empty to use the default text. Mention your privacy policy here if needed.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Conversation history', 'ruboja-assistants' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ruboja_assistants[show_history]" value="1" <?php checked( $settings['show_history'] ); ?>>
								<?php esc_html_e( 'Returning visitors see their earlier conversations', 'ruboja-assistants' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Remember visitors for', 'ruboja-assistants' ); ?>
									<input type="number" class="small-text" min="1" max="365" name="ruboja_assistants[history_days]" value="<?php echo esc_attr( (string) $settings['history_days'] ); ?>">
									<?php esc_html_e( 'days', 'ruboja-assistants' ); ?>
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'Sets a functional cookie with a random id. Anyone using the same browser sees these conversations, so keep this off for sensitive topics or shared computers.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ruboja-assistants-attribution"><?php esc_html_e( 'User attribution', 'ruboja-assistants' ); ?></label></th>
						<td>
							<select id="ruboja-assistants-attribution" name="ruboja_assistants[attribution]">
								<option value="none" <?php selected( $settings['attribution'], 'none' ); ?>><?php esc_html_e( 'None: all visitors chat anonymously', 'ruboja-assistants' ); ?></option>
								<option value="wp_user_email" <?php selected( $settings['attribution'], 'wp_user_email' ); ?>><?php esc_html_e( 'Logged-in WordPress users by e-mail address', 'ruboja-assistants' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'For intranets: the e-mail address of the logged-in user is sent to Ruboja, and the conversation appears in that Ruboja user\'s history.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ruboja-assistants-rate-limit"><?php esc_html_e( 'Rate limit', 'ruboja-assistants' ); ?></label></th>
						<td>
							<input type="number" class="small-text" min="0" max="1000" id="ruboja-assistants-rate-limit" name="ruboja_assistants[rate_limit]" value="<?php echo esc_attr( (string) $settings['rate_limit'] ); ?>">
							<span><?php esc_html_e( 'assistant starts per visitor IP address and hour (0 = unlimited)', 'ruboja-assistants' ); ?></span>
						</td>
					</tr>
				</table>
				</section>

				<section class="ruboja-assistants-admin__card">
				<h2><?php esc_html_e( 'Appearance', 'ruboja-assistants' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ruboja-assistants-color"><?php esc_html_e( 'Accent color', 'ruboja-assistants' ); ?></label></th>
						<td>
							<input type="color" id="ruboja-assistants-color" name="ruboja_assistants[color]" value="<?php echo esc_attr( $settings['color'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used for buttons and the header of the floating window. Can be overridden per block or shortcode.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ruboja header', 'ruboja-assistants' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ruboja_assistants[hide_header]" value="1" <?php checked( $settings['hide_header'] ); ?>>
								<?php esc_html_e( 'Hide the header line of Ruboja (logo, assistant name and organization)', 'ruboja-assistants' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Ruboja has no setting for this, so the plugin cuts off the top 60 pixels of the chat. If Ruboja changes its layout, switch this off.', 'ruboja-assistants' ); ?></p>
							<p>
								<label>
									<input type="checkbox" name="ruboja_assistants[hide_greeting]" value="1" <?php checked( $settings['hide_greeting'] ); ?>>
									<?php esc_html_e( 'Also cut off the greeting, e.g. "Nice to see you, Enrico!" (experimental)', 'ruboja-assistants' ); ?>
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'Only from a width of 640 pixels; in narrower windows such as the floating one the greeting may wrap and stays visible. Set the height of inline assistants about 100 pixels lower than without this option and rather a little too low, otherwise Ruboja centers the content and the greeting slides back into view. After the conversation has started, the top 100 pixels of the chat stay hidden.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ruboja-assistants-global"><?php esc_html_e( 'Floating assistant on every page', 'ruboja-assistants' ); ?></label></th>
						<td>
							<select id="ruboja-assistants-global" name="ruboja_assistants[global_assistant]">
								<option value=""><?php esc_html_e( 'None', 'ruboja-assistants' ); ?></option>
								<?php foreach ( $released as $id => $assistant ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['global_assistant'], $id ); ?>><?php echo esc_html( $assistant['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p>
								<label>
									<?php esc_html_e( 'Button label', 'ruboja-assistants' ); ?>
									<input type="text" class="regular-text" name="ruboja_assistants[global_label]" value="<?php echo esc_attr( $settings['global_label'] ); ?>" placeholder="<?php esc_attr_e( 'Title of the assistant', 'ruboja-assistants' ); ?>">
								</label>
							</p>
							<p>
								<label><input type="radio" name="ruboja_assistants[global_position]" value="right" <?php checked( $settings['global_position'], 'right' ); ?>> <?php esc_html_e( 'Bottom right', 'ruboja-assistants' ); ?></label>
								&nbsp;
								<label><input type="radio" name="ruboja_assistants[global_position]" value="left" <?php checked( $settings['global_position'], 'left' ); ?>> <?php esc_html_e( 'Bottom left', 'ruboja-assistants' ); ?></label>
							</p>
							<p class="description"><?php esc_html_e( 'Only released assistants are listed. Save after releasing an assistant to see it here.', 'ruboja-assistants' ); ?></p>
						</td>
					</tr>
				</table>
				</section>

				<?php submit_button(); ?>
			</form>

			<section class="ruboja-assistants-admin__card">
			<h2><?php esc_html_e( 'Embedding', 'ruboja-assistants' ); ?></h2>
			<p><?php esc_html_e( 'In the block editor, add the block "Ruboja Assistant" and choose an assistant. Elsewhere, use the shortcode:', 'ruboja-assistants' ); ?></p>
			<ul class="ruboja-assistants-admin__examples">
				<li><code>[ruboja_assistant id="…"]</code> – <?php esc_html_e( 'inline in the content', 'ruboja-assistants' ); ?></li>
				<li><code>[ruboja_assistant id="…" mode="floating" label="…"]</code> – <?php esc_html_e( 'as a floating chat button', 'ruboja-assistants' ); ?></li>
				<li><code>height="800" title="…" color="#1f3a5f" position="left"</code> – <?php esc_html_e( 'optional attributes', 'ruboja-assistants' ); ?></li>
			</ul>
			</section>
		</div>
		<?php
	}

	/**
	 * Shows the installed version with a link to the release notes.
	 *
	 * @return void
	 */
	private function render_version(): void {
		$headers    = get_file_data( PLUGIN_FILE, array( 'update_uri' => 'Update URI' ) );
		$repository = Updater::repository( (string) $headers['update_uri'] );

		printf(
			'<p class="ruboja-assistants-admin__version">%1$s%2$s</p>',
			esc_html(
				sprintf(
					/* translators: %s: plugin version. */
					__( 'Version %s', 'ruboja-assistants' ),
					VERSION
				)
			),
			'' !== $repository
				? sprintf( ' · <a href="%1$s">%2$s</a>', esc_url( 'https://github.com/' . $repository . '/releases' ), esc_html__( 'Release notes on GitHub', 'ruboja-assistants' ) )
				: ''
		);
	}

	/**
	 * Renders the API key field depending on where the key comes from.
	 *
	 * @return void
	 */
	private function render_api_key_field(): void {
		if ( Settings::api_key_from_constant() ) {
			printf(
				'<p><code>%1$s</code> %2$s</p>',
				esc_html( Settings::masked_api_key() ),
				esc_html(
					sprintf(
						/* translators: %s: constant name. */
						__( 'is defined in wp-config.php (%s) and cannot be changed here.', 'ruboja-assistants' ),
						Settings::API_KEY_CONSTANT
					)
				)
			);
			return;
		}

		$masked = Settings::masked_api_key();

		if ( Settings::has_unreadable_api_key() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The stored API key can no longer be read, probably because the security keys in wp-config.php changed. Please enter the key again.', 'ruboja-assistants' )
			);
		}

		if ( '' !== $masked ) {
			printf(
				'<p>%1$s <code>%2$s</code></p>',
				esc_html__( 'Stored key:', 'ruboja-assistants' ),
				esc_html( $masked )
			);
		}

		printf(
			'<input type="password" class="regular-text code" id="ruboja-assistants-api-key" name="ruboja_assistants_api_key" value="" autocomplete="off" spellcheck="false" placeholder="%s">',
			esc_attr( '' !== $masked ? __( 'Enter a new key to replace it', 'ruboja-assistants' ) : 'sk_live_…' )
		);

		if ( '' !== $masked ) {
			printf(
				'<p><label><input type="checkbox" name="ruboja_assistants_remove_key" value="1"> %s</label></p>',
				esc_html__( 'Remove stored key', 'ruboja-assistants' )
			);
		}

		printf(
			'<p class="description">%1$s</p><p class="description"><strong>%2$s</strong></p><p class="description">%3$s</p>',
			esc_html__( 'Create the key in Ruboja under Settings → API keys and choose "Optional" for user attribution, otherwise anonymous visitors cannot use the assistants. The key acts with the permissions of the person who creates it, so it only lists assistants that person may use.', 'ruboja-assistants' ),
			esc_html__( 'Ruboja greets anonymous visitors with the first name of the person who created the key. Create the key with a technical Ruboja user, for example "Website", that has access to the assistants you want to embed.', 'ruboja-assistants' ),
			esc_html(
				sprintf(
					/* translators: %s: constant name. */
					__( 'The key is stored encrypted. Alternatively define it in wp-config.php as %s.', 'ruboja-assistants' ),
					Settings::API_KEY_CONSTANT
				)
			)
		);
	}

	/**
	 * Renders the table of assistants with release checkboxes.
	 *
	 * @param array<string, array<string, mixed>> $assistants Known assistants.
	 * @return void
	 */
	private function render_assistant_table( array $assistants ): void {
		if ( empty( $assistants ) ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'No assistants loaded yet. Save an API key, then click "Test connection and refresh list".', 'ruboja-assistants' ) );
			return;
		}

		uasort(
			$assistants,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);

		?>
		<table class="widefat striped ruboja-assistants-admin__table">
			<thead>
				<tr>
					<th scope="col" class="check-column-wide"><?php esc_html_e( 'Released', 'ruboja-assistants' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Assistant', 'ruboja-assistants' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'ruboja-assistants' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Logged-in users only', 'ruboja-assistants' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'ruboja-assistants' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $assistants as $id => $assistant ) : ?>
					<?php $shortcode = sprintf( '[ruboja_assistant id="%s"]', $id ); ?>
					<tr class="<?php echo ! empty( $assistant['missing'] ) ? 'is-missing' : ''; ?>">
						<td>
							<input type="checkbox" id="ruboja-assistants-enabled-<?php echo esc_attr( $id ); ?>" name="ruboja_assistants_enabled[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( ! empty( $assistant['enabled'] ) ); ?>>
						</td>
						<td>
							<label for="ruboja-assistants-enabled-<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $assistant['title'] ); ?></strong></label>
							<?php if ( ! empty( $assistant['missing'] ) ) : ?>
								<br><span class="ruboja-assistants-admin__missing"><?php esc_html_e( 'No longer accessible with this API key. It is not shown on the website.', 'ruboja-assistants' ); ?></span>
							<?php elseif ( '' !== (string) $assistant['description'] ) : ?>
								<span class="description ruboja-assistants-admin__summary" title="<?php echo esc_attr( $assistant['description'] ); ?>"><?php echo esc_html( $assistant['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $assistant['category'] ); ?></td>
						<td>
							<input type="checkbox" name="ruboja_assistants_logged_in_only[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: assistant title. */ __( 'Only for logged-in users: %s', 'ruboja-assistants' ), $assistant['title'] ) ); ?>" <?php checked( ! empty( $assistant['logged_in_only'] ) ); ?>>
						</td>
						<td>
							<code class="ruboja-assistants-admin__shortcode" title="<?php echo esc_attr( $shortcode ); ?>"><?php echo esc_html( $shortcode ); ?></code>
							<button type="button" class="button button-small ruboja-assistants-admin__copy" data-copy="<?php echo esc_attr( $shortcode ); ?>"><?php esc_html_e( 'Copy', 'ruboja-assistants' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Fetches the assistant list and stores the outcome as a notice.
	 *
	 * @return void
	 */
	private function sync_and_notify(): void {
		$result = Api_Client::from_settings()->list_assistants();

		if ( is_wp_error( $result ) ) {
			$this->notify(
				'error',
				sprintf(
					/* translators: %s: error message from Ruboja. */
					__( 'Connection to Ruboja failed: %s', 'ruboja-assistants' ),
					$result->get_error_message()
				)
			);
			return;
		}

		Settings::merge_remote_assistants( $result );

		$this->notify(
			'success',
			sprintf(
				/* translators: %d: number of assistants. */
				_n( 'Connected. %d assistant is accessible with this API key.', 'Connected. %d assistants are accessible with this API key.', count( $result ), 'ruboja-assistants' ),
				count( $result )
			)
		);
	}

	/**
	 * Verifies capability and nonce of an admin-post request.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'ruboja-assistants' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Reads a posted list of assistant ids.
	 *
	 * @param string $field Field name.
	 * @return string[]
	 */
	private function posted_ids( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? map_deep( wp_unslash( $_POST[ $field ] ), 'sanitize_text_field' ) : array();
		$ids = array_map( 'strtolower', array_filter( $raw, 'is_string' ) );

		return array_values( array_filter( $ids, array( Settings::class, 'is_valid_id' ) ) );
	}

	/**
	 * Remembers a notice for the next page load of the current user.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 * @return void
	 */
	private function notify( string $type, string $message ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints and clears the stored notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}
}
