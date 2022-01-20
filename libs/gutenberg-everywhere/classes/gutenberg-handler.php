<?php

require_once __DIR__ . '/iso-gutenberg.php';

abstract class Friends_Gutenberg_Handler {
	private $doing_hook = null;

	/**
	 * Direct copy of core `do_blocks`, but for comments.
	 *
	 * This also has the benefit that we don't run `wpautop` on block transformed comments, potentially breaking them.
	 *
	 * @param String $content Comment text
	 * @return String
	 */
	public function do_blocks( $content, $hook ) {
		$blocks = parse_blocks( $content );
		$output = '';

		foreach ( $blocks as $block ) {
			$output .= render_block( $block );
		}

		// If there are blocks in this content, we shouldn't run wpautop() on it later.
		$priority = has_filter( $hook, 'wpautop' );
		if ( false !== $priority && doing_filter( $hook ) && has_blocks( $content ) ) {
			$this->doing_hook = $hook;
			remove_filter( $hook, 'wpautop', $priority );
			add_filter( $hook, [ $this, 'restore_wpautop_hook' ], $priority + 1 );
		}

		return ltrim( $output );
	}

	/**
	 * Restore the above `remove_filter` for comments
	 *
	 * @param String $content Comment ext
	 * @return String
	 **/
	public function restore_wpautop_hook( $content ) {
		$current_priority = has_filter( $this->doing_hook, [ $this, 'restore_wpautop_hook' ] );

		if ( $current_priority !== false ) {
			add_filter( $this->doing_hook, 'wpautop', $current_priority - 1 );
			remove_filter( $this->doing_hook, [ $this, 'restore_wpautop_hook' ], $current_priority );
		}

		$this->doing_hook = null;
		return $content;
	}

	public function can_show_admin_editor( $hook ) {
		return false;
	}

	/**
	 * Add the Gutenberg editor to the comment editor, but only if it includes blocks.
	 *
	 * @param string $editor Editor HTML.
	 * @return string
	 */
	public function the_editor( $editor ) {
		$editor = preg_replace( '@.*?(<textarea.*?</textarea>).*@', '$1', $editor );

		return '<div class="gutenberg-everywhere iso-editor__loading">' . $editor . '</div>';
	}

	public function wp_editor_settings( $settings, $editor_id ) {
		$settings['tinymce'] = false;
		$settings['quicktags'] = false;
		return $settings;
	}

	public function get_editor_type() {
		return 'core';
	}

	/**
	 * Remove blocks that aren't allowed
	 *
	 * @param string $content
	 * @return string
	 */
	public function remove_blocks( $content ) {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}

		$allowed = $this->get_allowed_blocks();
		$blocks = parse_blocks( $content );
		$output = '';

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], $allowed, true ) ) {
				$output .= serialize_block( $block );
			}
		}

		return ltrim( $output );
	}

	/**
	 * Get a list of allowed blocks by looking at the allowed comment tags
	 *
	 * @return string[]
	 */
	private function get_allowed_blocks() {
		global $allowedtags;

		$allowed = [ 'core/paragraph', 'core/list', 'core/code' ];
		$convert = [
			'blockquote' => 'core/quote',
			'h1' => 'core/heading',
			'h2' => 'core/heading',
			'h3' => 'core/heading',
			'img' => 'core/image',
			'ul' => 'core/list',
			'ol' => 'core/list',
			'pre' => 'core/code',
			'table' => 'core/table',
			'video' => 'core/video',
		];

		foreach ( array_keys( $allowedtags ) as $tag ) {
			if ( isset( $convert[ $tag ] ) ) {
				$allowed[] = $convert[ $tag ];
			}
		}

		return apply_filters( 'gutenberg_everywhere_allowed_blocks', array_unique( $allowed ), $this->get_editor_type() );
	}

	/**
	 * Load Gutenberg if a comment form is enabled
	 *
	 * @return void
	 */
	public function load_editor( $textarea, $container = null ) {
		$this->gutenberg = new Friends_GutenbergEverywhere_Editor();
		$this->gutenberg->load();

		$asset_file = dirname( __DIR__ ) . '/build/index.asset.php';
		$asset = file_exists( $asset_file ) ? require_once $asset_file : null;
		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : [];
		$version = isset( $asset['version'] ) ? $asset['version'] : time();

		$js_dependencies = array_filter(
			$dependencies,
			function( $depend ) {
				return strpos( $depend, '.css' ) === false;
			}
		);

		$css_dependencies = array_filter(
			$dependencies,
			function( $depend ) {
				return strpos( $depend, '.css' ) !== false;
			}
		);

		$plugin = dirname( dirname( __FILE__ ) ) . '/gutenberg-everywhere.php';

		wp_register_script( 'gutenberg-everywhere', plugins_url( 'build/index.js', $plugin ), $js_dependencies, $version, true );
		wp_enqueue_script( 'gutenberg-everywhere' );

		wp_register_style( 'gutenberg-everywhere', plugins_url( 'build/style-index.css', $plugin ), $css_dependencies, $version );
		wp_enqueue_style( 'gutenberg-everywhere' );

		// Settings for the editor
		$settings = [
			'editor' => $this->gutenberg->get_editor_settings(),
			'iso' => [
				'blocks' => [
					'allowBlocks' => $this->get_allowed_blocks(),
				],
				'moreMenu' => false,
			],
			'saveTextarea' => $textarea,
			'container' => $container,
			'editorType' => $this->get_editor_type(),
		];

		wp_localize_script( 'gutenberg-everywhere', 'wpGutenbergEverywhere', $settings );
	}
}
