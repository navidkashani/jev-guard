<?php
/**
 * Comments-list column tests.
 *
 * @package JevGuard
 */

use JevGuard\Admin\CommentsScreen;
use JevGuard\Integrations\Comments;
use JevGuard\Plugin;
use JevGuard\Verdict;

/**
 * The "Jev" column cell: decision pill plus category / reason lines.
 */
class CommentsScreenTest extends JevGuard_TestCase {

	/**
	 * Cell markup for a comment carrying the given verdict meta.
	 *
	 * @param array|null $meta Verdict meta (`null` for an unchecked comment).
	 * @return string
	 */
	private function cell( $meta ): string {
		$comment_id = self::factory()->comment->create();
		if ( null !== $meta ) {
			update_comment_meta( $comment_id, Comments::META, $meta );
		}
		return ( new CommentsScreen( Plugin::instance() ) )->cell_html( $comment_id );
	}

	/**
	 * Verdict meta from stubbed answers, decided with the default thresholds.
	 *
	 * @param float  $spam     Spam probability.
	 * @param float  $genuine  Genuine probability.
	 * @param string $category Category.
	 * @return array
	 */
	private function verdict_meta( float $spam, float $genuine, string $category ): array {
		$verdict = Verdict::from_answers( JevGuard_HttpStub::answers_body( $spam, $genuine, 0.0, $category )['answers'], 'jev-1.13.0' );
		$verdict->decide(
			array(
				'spam_threshold' => 0.85,
				'hold_threshold' => 0.5,
			)
		);
		return $verdict->to_array();
	}

	public function test_held_conflict_shows_pill_category_and_reason() {
		$html = $this->cell( $this->verdict_meta( 0.63, 0.8, 'commercial_promotion' ) );

		$this->assertStringContainsString( 'jev-guard-cell--hold', $html );
		$this->assertStringContainsString( '<span class="jev-guard-pill">Held 63%</span>', $html );
		$this->assertStringContainsString( '<span class="jev-guard-detail">Commercial promotion</span>', $html );
		// 0.63 is borderline, not a conflict: no reason line.
		$this->assertStringNotContainsString( 'responds to the page', $html );

		$html = $this->cell( $this->verdict_meta( 0.99, 0.8, 'commercial_promotion' ) );
		$this->assertStringContainsString( '<span class="jev-guard-pill">Held 99%</span>', $html );
		$this->assertStringContainsString( '<span class="jev-guard-detail">looks like spam but responds to the page</span>', $html );
		$this->assertStringContainsString( 'title="Reason: looks like spam but responds to the page. Model jev-1.13.0, ', $html );
	}

	public function test_spam_shows_pill_and_category() {
		$html = $this->cell( $this->verdict_meta( 0.99, 0.1, 'seo_link_spam' ) );
		$this->assertStringContainsString( 'jev-guard-cell--spam', $html );
		$this->assertStringContainsString( '<span class="jev-guard-pill">Spam 99%</span>', $html );
		$this->assertStringContainsString( '<span class="jev-guard-detail">SEO link spam</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'jev-guard-detail' ) );
	}

	public function test_allowed_legitimate_shows_pill_only() {
		$html = $this->cell( $this->verdict_meta( 0.06, 0.9, 'legitimate' ) );
		$this->assertStringContainsString( 'jev-guard-cell--allow', $html );
		$this->assertStringContainsString( '<span class="jev-guard-pill">6% spam</span>', $html );
		$this->assertStringNotContainsString( 'jev-guard-detail', $html );

		// A non-legitimate category on an allowed comment is still worth a line.
		$html = $this->cell( $this->verdict_meta( 0.3, 0.9, 'commercial_promotion' ) );
		$this->assertStringContainsString( '<span class="jev-guard-detail">Commercial promotion</span>', $html );
	}

	public function test_unchecked_comment_has_no_pill() {
		$html = $this->cell( null );
		$this->assertStringContainsString( 'jev-guard-cell--none', $html );
		$this->assertStringContainsString( 'Not checked', $html );
		$this->assertStringNotContainsString( 'jev-guard-pill', $html );
	}

	public function test_error_and_skipped_use_grey_pill() {
		$html = $this->cell(
			array(
				'error' => array(
					'code'    => 'timeout',
					'message' => 'cURL error 28',
				),
			)
		);
		$this->assertStringContainsString( 'jev-guard-cell--error', $html );
		$this->assertStringContainsString( '<span class="jev-guard-pill">Error</span>', $html );
		$this->assertStringContainsString( '<span class="jev-guard-detail">Timed out</span>', $html );
		$this->assertStringContainsString( 'title="cURL error 28"', $html );

		$html = $this->cell( array( 'skipped' => 'moderator' ) );
		$this->assertStringContainsString( 'jev-guard-cell--skipped', $html );
		$this->assertStringContainsString( '<span class="jev-guard-pill">Skipped</span>', $html );
		$this->assertStringContainsString( '<span class="jev-guard-detail">moderator</span>', $html );
	}
}
