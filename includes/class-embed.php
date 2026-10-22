<?php
/**
 * Front-end output: shortcode, block and site-wide floating assistant.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the markup that the front-end script turns into an assistant.
 */
final class Embed {

	const SHORTCODE      = 'ruboja_assistant';
	const HANDLE         = 'ruboja-assistants';
	const BLOCK_NAME     = 'ruboja/assistant';
	const MIN_HEIGHT     = 320;
	const MAX_HEIGHT     = 2000;
	const DEFAULT_HEIGHT = 720;

	/**
	 * Number of rendered assistants, for unique element ids.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Whether the configuration script has been added.
	 *
	 * @var bool
	 */
	private static $configured = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_footer', array( $this, 'render_global' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Registers script and style.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style( self::HANDLE, plugins_url( 'assets/css/frontend.css', PLUGIN_FILE ), array(), self::asset_version( 'assets/css/frontend.css' ) );
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/js/frontend.js', PLUGIN_FILE ),
			array(),
			self::asset_version( 'assets/js/frontend.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Cache-busting version of an asset: the plugin version, plus the file time with SCRIPT_DEBUG.
	 *
	 * @param string $path Path relative to the plugin folder.
	 * @return string
	 */
	public static function asset_version( string $path ): string {
		$file = dirname( PLUGIN_FILE ) . '/' . $path;

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return VERSION . '-' . filemtime( $file );
		}

		return VERSION;
	}

	/**
	 * Registers the block from block.json.
	 *
	 * @return void
	 */
	public function register_block(): void {
		register_block_type(
			dirname( PLUGIN_FILE ) . '/blocks/assistant',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);

		wp_set_script_translations(
			generate_block_asset_handle( self::BLOCK_NAME, 'editorScript' ),
			'ruboja-assistants',
			dirname( PLUGIN_FILE ) . '/languages'
		);
	}

	/**
	 * Shortcode [ruboja_assistant id="…" mode="inline|floating"].
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => '',
				'mode'     => 'inline',
				'height'   => self::DEFAULT_HEIGHT,
				'title'    => '',
				'label'    => '',
				'color'    => '',
				'position' => 'right',
			),
			$atts,
			self::SHORTCODE
		);

		return $this->render(
			array(
				'assistantId' => $atts['id'],
				'mode'        => $atts['mode'],
				'height'      => $atts['height'],
				'title'       => $atts['title'],
				'buttonLabel' => $atts['label'],
				'color'       => $atts['color'],
				'position'    => $atts['position'],
			)
		);
	}

	/**
	 * Render callback of the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes ): string {
		return $this->render( $attributes );
	}

	/**
	 * Prints the site-wide floating assistant, if one is configured.
	 *
	 * @return void
	 */
	public function render_global(): void {
		$id = (string) Settings::get( 'global_assistant' );

		if ( '' === $id ) {
			return;
		}

		$html = $this->render(
			array(
				'assistantId' => $id,
				'mode'        => 'floating',
				'buttonLabel' => (string) Settings::get( 'global_label' ),
				'position'    => (string) Settings::get( 'global_position' ),
			)
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value.
	}

	/**
	 * Builds the markup of one assistant.
	 *
	 * @param array<string, mixed> $args assistantId, mode, height, title, buttonLabel, color, position.
	 * @return string
	 */
	public function render( array $args ): string {
		$id        = strtolower( trim( (string) ( $args['assistantId'] ?? '' ) ) );
		$assistant = Settings::get_released( $id );

		if ( null === $assistant ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return sprintf(
					'<div class="ruboja-assistant-notice">%s</div>',
					esc_html__( 'Ruboja assistant: this assistant is not released or no longer available. Check Settings → Ruboja Assistants.', 'ruboja-assistants' )
				);
			}

			return '';
		}

		if ( ! empty( $assistant['logged_in_only'] ) && ! is_user_logged_in() ) {
			return '';
		}

		$mode     = ( isset( $args['mode'] ) && 'floating' === $args['mode'] ) ? 'floating' : 'inline';
		$height   = isset( $args['height'] ) ? (int) $args['height'] : self::DEFAULT_HEIGHT;
		$height   = min( self::MAX_HEIGHT, max( self::MIN_HEIGHT, $height ) );
		$title    = trim( (string) ( $args['title'] ?? '' ) );
		$title    = '' !== $title ? $title : (string) $assistant['title'];
		$label    = trim( (string) ( $args['buttonLabel'] ?? '' ) );
		$label    = '' !== $label ? $label : $title;
		$color    = sanitize_hex_color( (string) ( $args['color'] ?? '' ) );
		$color    = $color ? $color : (string) Settings::get( 'color' );
		$position = ( isset( $args['position'] ) && 'left' === $args['position'] ) ? 'left' : 'right';
		$consent  = (bool) Settings::get( 'consent_required' );
		$uid      = 'ruboja-assistant-' . ( ++self::$count );
		$classes  = array( 'ruboja-assistant', 'ruboja-assistant--' . $mode );

		if ( 'floating' === $mode ) {
			$classes[] = 'ruboja-assistant--' . $position;
		}

		if ( ! empty( $args['className'] ) ) {
			$classes = array_merge( $classes, array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['className'] ) ) );
		}

		$this->enqueue();

		$root_attributes = sprintf(
			'id="%1$s" class="%2$s" style="%3$s" data-ruboja-assistant="%4$s"',
			esc_attr( $uid ),
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_attr( sprintf( '--ruboja-color:%1$s;--ruboja-on-color:%2$s;--ruboja-height:%3$dpx;', $color, self::contrast_color( $color ), $height ) ),
			esc_attr(
				(string) wp_json_encode(
					array(
						'assistant' => $assistant['id'],
						'mode'      => $mode,
						'title'     => $title,
						'consent'   => $consent,
					)
				)
			)
		);

		$stage = $this->stage_markup( $title, $consent, 'inline' === $mode );

		if ( 'inline' === $mode ) {
			return sprintf( '<div %1$s>%2$s</div>', $root_attributes, $stage );
		}

		return sprintf(
			'<div %1$s>
				<div id="%2$s-panel" class="ruboja-assistant__panel" role="dialog" aria-labelledby="%2$s-title" hidden>
					<div class="ruboja-assistant__header">
						<span id="%2$s-title" class="ruboja-assistant__heading">%3$s</span>
						<button type="button" class="ruboja-assistant__close" aria-label="%4$s"><span aria-hidden="true">&times;</span></button>
					</div>
					%5$s
				</div>
				<button type="button" class="ruboja-assistant__launcher" aria-expanded="false" aria-controls="%2$s-panel">
					<svg class="ruboja-assistant__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
					<span class="ruboja-assistant__label">%6$s</span>
				</button>
			</div>',
			$root_attributes,
			esc_attr( $uid ),
			esc_html( $title ),
			esc_attr__( 'Close assistant', 'ruboja-assistants' ),
			$stage,
			esc_html( $label )
		);
	}

	/**
	 * Markup of the area that later holds the iframe.
	 *
	 * @param string $title      Assistant title.
	 * @param bool   $consent    Whether the consent notice is shown.
	 * @param bool   $show_title Whether to repeat the title (inline mode has no header).
	 * @return string
	 */
	private function stage_markup( string $title, bool $consent, bool $show_title ): string {
		return sprintf(
			'<div class="ruboja-assistant__stage">
				<div class="ruboja-assistant__placeholder">
					%1$s
					%2$s
					<button type="button" class="ruboja-assistant__start">%3$s</button>
					<p class="ruboja-assistant__status" role="status" aria-live="polite"></p>
					<noscript><p>%4$s</p></noscript>
				</div>
			</div>',
			$show_title ? '<p class="ruboja-assistant__title">' . esc_html( $title ) . '</p>' : '',
			$consent ? '<p class="ruboja-assistant__consent">' . esc_html( Settings::consent_text() ) . '</p>' : '',
			esc_html__( 'Start assistant', 'ruboja-assistants' ),
			esc_html__( 'Please enable JavaScript to use the assistant.', 'ruboja-assistants' )
		);
	}

	/**
	 * Enqueues the front-end assets once and passes the configuration.
	 *
	 * @return void
	 */
	private function enqueue(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		if ( self::$configured ) {
			return;
		}

		self::$configured = true;

		$config = array(
			'restUrl' => esc_url_raw( rest_url( Rest_Controller::ROUTE_NAMESPACE . '/' ) ),
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'    => array(
				'loading' => __( 'Starting the assistant …', 'ruboja-assistants' ),
				'error'   => __( 'The assistant could not be started. Please try again later.', 'ruboja-assistants' ),
				'retry'   => __( 'Try again', 'ruboja-assistants' ),
			),
		);

		wp_add_inline_script( self::HANDLE, 'window.RubojaAssistants = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Picks black or white text for a background color (WCAG relative luminance).
	 *
	 * @param string $hex Background color, #rgb or #rrggbb.
	 * @return string
	 */
	public static function contrast_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$channels = array_map(
			static function ( $pair ) {
				$value = hexdec( $pair ) / 255;

				return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			},
			str_split( $hex, 2 )
		);

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.179 ? '#111111' : '#ffffff';
	}
}
