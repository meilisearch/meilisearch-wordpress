<?php
/**
 * Suggested privacy policy text.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Ops;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Registrable;

/**
 * Suggested privacy policy text (spec § 11.4).
 */
final class Privacy implements Registrable {

	/**
	 * Registers the privacy policy hook.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Suggests the policy text in Settings > Privacy.
	 */
	public function add_policy_content(): void {
		wp_add_privacy_policy_content( 'Meilisearch', $this->policy_text() );
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return string Sanitized HTML.
	 */
	public function policy_text(): string {
		$paragraphs = array(
			'<p class="privacy-policy-tutorial">' . esc_html__( 'The Meilisearch plugin sends the public content of your site to the Meilisearch server you configured, and search terms typed by visitors to the same server. Adapt the text below if you enabled other plugins that add personal data to posts, or if you index meta keys that contain personal data.', 'meilisearch' ) . '</p>',
			'<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'meilisearch' ) . ' </strong>',
			'<p>' . esc_html__( 'This site uses Meilisearch to provide its search feature. The title, content, excerpt, categories, tags, product details and other public information of published content, together with the display name of the author of that content, are sent to the Meilisearch server chosen by the site owner. That server is either operated by the site owner or provided by Meilisearch Cloud.', 'meilisearch' ) . '</p>',
			'<p>' . esc_html__( 'When you search this site, your search terms are sent from this site to that server to find matching content. If as-you-type suggestions are enabled, the characters you type in the search field are sent directly from your browser to that server while you type; like any web request, this request includes your IP address and information about your browser.', 'meilisearch' ) . '</p>',
			'<p>' . esc_html__( 'The plugin does not set tracking cookies, does not record which results you click, and does not index comments, email addresses or unpublished content.', 'meilisearch' ) . '</p>',
		);

		return wp_kses_post( implode( "\n", $paragraphs ) );
	}
}
