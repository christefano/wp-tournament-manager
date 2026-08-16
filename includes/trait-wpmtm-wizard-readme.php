<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone README popup page and its small Markdown renderer, extracted
 * verbatim from WPMTM_Wizard (2026-07-29 segmentation). render_readme_page
 * is the admin_post_wpmtm_readme handler registered in the wizard
 * constructor; render_markdown and render_inline are its private helpers.
 * Composed into WPMTM_Wizard via a use statement; behavior is identical.
 */
trait WPMTM_Wizard_Readme {
	/**
	 * Outputs the plugin README.md as a small standalone HTML page (its own
	 * <html> document), opened by the setup guide's "Documentation" link in a
	 * 920x740 popup window (assets/wpmtm-admin.js) sized to match the
	 * Standings/Wall chart print windows. Capability- and nonce-gated. The
	 * README is the plugin's own bundled file, not user input, but its text is
	 * still escaped and run through a small, deliberately limited Markdown
	 * renderer (render_markdown()) rather than echoed raw.
	 */
	public function render_readme_page() {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'No permission to view this.', 'wp-tournament-manager' ) );
		}
		check_admin_referer( 'wpmtm_readme' );

		$path = WPMTM_PLUGIN_DIR . 'README.md';
		$md   = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin file from disk, not a remote URL.
		$body = '' !== $md
			? $this->render_markdown( $md )
			: '<p>' . esc_html__( 'The documentation file could not be read.', 'wp-tournament-manager' ) . '</p>';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Tournament Manager documentation', 'wp-tournament-manager' ); ?></title>
	<style>
		body { margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #1d2327; background: #fff; }
		.wpmtm-doc { max-width: 760px; margin: 0 auto; }
		.wpmtm-doc h1 { font-size: 22px; margin: 0 0 16px; }
		.wpmtm-doc h2 { font-size: 18px; margin: 28px 0 10px; border-bottom: 1px solid #e0e0e0; padding-bottom: 4px; }
		.wpmtm-doc h3 { font-size: 15px; margin: 20px 0 8px; }
		.wpmtm-doc p { margin: 0 0 12px; }
		.wpmtm-doc ul, .wpmtm-doc ol { margin: 0 0 12px; padding-left: 24px; }
		.wpmtm-doc li { margin: 0 0 4px; }
		.wpmtm-doc code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; font-size: 0.92em; }
		.wpmtm-doc pre { background: #f6f7f7; padding: 12px; border-radius: 4px; overflow-x: auto; }
		.wpmtm-doc pre code { background: none; padding: 0; }
		.wpmtm-doc a { color: #2271b1; }
		.wpmtm-doc hr { border: 0; border-top: 1px solid #e0e0e0; margin: 24px 0; }
	</style>
</head>
<body>
	<div class="wpmtm-doc">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_markdown() escapes all text via esc_html() before emitting a fixed, closed set of tags; see that method. ?>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * A deliberately small Markdown-to-HTML renderer for the bundled README,
	 * NOT a general-purpose one. Every line of text is esc_html()'d first, so
	 * the only HTML that ever reaches output is the fixed tag set this method
	 * emits (headings, paragraphs, lists, code, links, hr). Handles the subset
	 * the README actually uses: ATX headings, fenced code blocks, unordered
	 * and ordered lists, horizontal rules, inline code, bold, italic, and
	 * links. Anything else degrades to escaped plain text in a paragraph.
	 *
	 * @param string $md Raw Markdown.
	 * @return string HTML.
	 */
	protected function render_markdown( $md ) {
		$lines = preg_split( '/\r\n|\r|\n/', $md );
		$html  = '';
		$in_code = false;
		$list_type = ''; // '', 'ul', or 'ol'.

		$close_list = function () use ( &$html, &$list_type ) {
			if ( '' !== $list_type ) {
				$html .= '</' . $list_type . ">\n";
				$list_type = '';
			}
		};

		foreach ( $lines as $line ) {
			// Fenced code block toggle.
			if ( preg_match( '/^```/', $line ) ) {
				$close_list();
				if ( $in_code ) {
					$html   .= "</code></pre>\n";
					$in_code = false;
				} else {
					$html   .= '<pre><code>';
					$in_code = true;
				}
				continue;
			}
			if ( $in_code ) {
				$html .= esc_html( $line ) . "\n";
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s*([-*_])(\s*\1){2,}\s*$/', $line ) ) {
				$close_list();
				$html .= "<hr>\n";
				continue;
			}

			// Headings. A slugified id is emitted so the setup guide's
			// contextual-help icon can deep-link to a step's own section
			// (readme_anchor()); sanitize_title() matches the fragment that
			// helper builds.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$close_list();
				$level = min( 6, strlen( $m[1] ) );
				$id    = sanitize_title( $m[2] );
				$html .= '<h' . $level . ' id="' . esc_attr( $id ) . '">' . $this->render_inline( $m[2] ) . '</h' . $level . ">\n";
				continue;
			}

			// Unordered list item.
			if ( preg_match( '/^\s*[-*+]\s+(.*)$/', $line, $m ) ) {
				if ( 'ul' !== $list_type ) {
					$close_list();
					$html     .= "<ul>\n";
					$list_type = 'ul';
				}
				$html .= '<li>' . $this->render_inline( $m[1] ) . "</li>\n";
				continue;
			}

			// Ordered list item.
			if ( preg_match( '/^\s*\d+[.)]\s+(.*)$/', $line, $m ) ) {
				if ( 'ol' !== $list_type ) {
					$close_list();
					$html     .= "<ol>\n";
					$list_type = 'ol';
				}
				$html .= '<li>' . $this->render_inline( $m[1] ) . "</li>\n";
				continue;
			}

			// Blank line ends any list; paragraph break otherwise.
			if ( '' === trim( $line ) ) {
				$close_list();
				continue;
			}

			$close_list();
			$html .= '<p>' . $this->render_inline( $line ) . "</p>\n";
		}

		if ( $in_code ) {
			$html .= "</code></pre>\n";
		}
		$close_list();

		return $html;
	}

	/**
	 * Inline Markdown (bold, italic, code, links) on a single line. Escapes
	 * first, then applies formatting on the escaped text; the link regex reads
	 * a plain URL and runs it through esc_url(), so nothing unescaped ever
	 * reaches output.
	 *
	 * @param string $text
	 * @return string HTML.
	 */
	protected function render_inline( $text ) {
		$text = esc_html( $text );
		// Inline code first, so ** or _ inside backticks is left literal.
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		// Links [label](url). esc_url() the captured URL defensively.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
			},
			$text
		);
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(^|[^*])\*([^*]+)\*/', '$1<em>$2</em>', $text );
		return $text;
	}
}
