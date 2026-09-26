<?php
/**
 * Mount point of the settings app. $args['built']: whether public/app/settings.js exists.
 *
 * @package SpamLens
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap" style="margin:0">
	<div id="spamlens-admin">
		<?php if ( empty( $args['built'] ) ) : ?>
			<div class="notice notice-error" style="margin:20px"><p><?php esc_html_e( 'The SpamLens settings app is not built. Run npm install and npm run build in the plugin folder, or install the release zip.', 'spamlens' ); ?></p></div>
		<?php endif; ?>
	</div>
</div>
