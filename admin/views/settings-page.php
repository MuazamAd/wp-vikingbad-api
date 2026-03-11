<?php defined( 'ABSPATH' ) || exit;

$api_categories = get_option( 'vikingbad_api_categories', [] );
$category_map   = get_option( 'vikingbad_category_map', [] );

// Get all WC product categories for the dropdown.
$wc_categories = get_terms( [
	'taxonomy'   => 'product_cat',
	'hide_empty' => false,
	'orderby'    => 'name',
] );
?>

<div class="wrap vikingbad-settings">
	<h1><?php esc_html_e( 'Vikingbad Innstillinger', 'vikingbad' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'vikingbad_settings' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="vikingbad_api_token"><?php esc_html_e( 'API-nokkel', 'vikingbad' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="vikingbad_api_token"
						name="vikingbad_api_token"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Skriv inn din Vikingbad API-nokkel', 'vikingbad' ); ?>"
						value=""
						autocomplete="off"
					/>
					<p class="description">
						<?php
						$has_token = ! empty( get_option( 'vikingbad_api_token', '' ) );
						if ( $has_token ) {
							esc_html_e( 'En nokkel er lagret. Skriv inn en ny verdi for a erstatte den, eller la feltet sta tomt for a beholde gjeldende nokkel.', 'vikingbad' );
						} else {
							esc_html_e( 'Skriv inn din Vikingbad API-nokkel.', 'vikingbad' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Lagre innstillinger', 'vikingbad' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Test tilkobling', 'vikingbad' ); ?></h2>
	<p>
		<button type="button" id="vikingbad-test-connection" class="button button-secondary">
			<?php esc_html_e( 'Test tilkobling', 'vikingbad' ); ?>
		</button>
		<span id="vikingbad-test-result" class="vikingbad-test-result"></span>
	</p>

	<hr />

	<h2><?php esc_html_e( 'Kategorikartlegging', 'vikingbad' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Koble Vikingbad API-kategorier til dine eksisterende WooCommerce-kategorier. Kategorier uten kobling opprettes automatisk under import.', 'vikingbad' ); ?>
	</p>

	<p>
		<button type="button" id="vikingbad-scan-categories" class="button button-secondary">
			<?php esc_html_e( 'Skann API-kategorier', 'vikingbad' ); ?>
		</button>
		<button type="button" id="vikingbad-scan-fresh" class="button button-link-delete" style="margin-left:8px;">
			<?php esc_html_e( 'Start ny skanning', 'vikingbad' ); ?>
		</button>
		<span id="vikingbad-scan-result" class="vikingbad-test-result"></span>
	</p>
	<?php
	$scan_progress = get_option( 'vikingbad_scan_progress', [] );
	if ( ! empty( $scan_progress['last_page'] ) && $scan_progress['last_page'] < ( $scan_progress['total_pages'] ?? 0 ) ) :
	?>
	<p class="description" style="color:#d63638;">
		<?php printf(
			esc_html__( 'Forrige skanning stoppet pa side %1$d av %2$d (%3$d produkter skannet, %4$d kategorier funnet). Klikk "Skann API-kategorier" for a fortsette.', 'vikingbad' ),
			$scan_progress['last_page'],
			$scan_progress['total_pages'],
			$scan_progress['scanned'] ?? 0,
			count( $api_categories )
		); ?>
	</p>
	<?php endif; ?>

	<div id="vikingbad-category-map-wrap" <?php echo empty( $api_categories ) ? 'style="display:none"' : ''; ?>>
		<table class="widefat vikingbad-category-map-table" style="max-width:700px;margin-top:15px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'API-kategori', 'vikingbad' ); ?></th>
					<th><?php esc_html_e( 'WooCommerce-kategori', 'vikingbad' ); ?></th>
				</tr>
			</thead>
			<tbody id="vikingbad-category-map-body">
				<?php foreach ( $api_categories as $api_cat ) :
					$api_name   = $api_cat['name'];
					$api_level  = (int) ( $api_cat['level'] ?? 1 );
					$mapped_id  = $category_map[ $api_name ] ?? '';
					$indent     = str_repeat( '&mdash; ', $api_level - 1 );
				?>
				<tr>
					<td>
						<?php echo $indent; ?><strong><?php echo esc_html( $api_name ); ?></strong>
						<span class="description">(Niva <?php echo esc_html( $api_level ); ?>)</span>
					</td>
					<td>
						<select name="category_map[<?php echo esc_attr( $api_name ); ?>]" class="vikingbad-cat-select">
							<option value=""><?php esc_html_e( '— Opprett automatisk —', 'vikingbad' ); ?></option>
							<?php foreach ( $wc_categories as $wc_cat ) : ?>
								<option value="<?php echo esc_attr( $wc_cat->term_id ); ?>" <?php selected( $mapped_id, $wc_cat->term_id ); ?>>
									<?php echo esc_html( $wc_cat->name ); ?> (ID: <?php echo esc_html( $wc_cat->term_id ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:10px;">
			<button type="button" id="vikingbad-save-category-map" class="button button-primary">
				<?php esc_html_e( 'Lagre kategorikartlegging', 'vikingbad' ); ?>
			</button>
			<span id="vikingbad-map-result" class="vikingbad-test-result"></span>
		</p>
	</div>
</div>

<script>
jQuery(function($) {
	var ajaxUrl = vikingbadImport.ajaxUrl;
	var nonce   = vikingbadImport.nonce;

	// Test Connection.
	$('#vikingbad-test-connection').on('click', function() {
		var $btn    = $(this);
		var $result = $('#vikingbad-test-result');

		$btn.prop('disabled', true);
		$result.text('Tester...').removeClass('success error');

		$.post(ajaxUrl, { action: 'vikingbad_test_connection', nonce: nonce })
		.done(function(r) {
			$result.text(r.data.message).addClass(r.success ? 'success' : 'error');
		})
		.fail(function() {
			$result.text('Foresporselen feilet.').addClass('error');
		})
		.always(function() { $btn.prop('disabled', false); });
	});

	// Scan Categories — page by page with resume support.
	var scanTotals = { scanned: 0, found: 0 };

	function scanPage(page, totalPages) {
		$('#vikingbad-scan-result')
			.text('Skanner side ' + page + ' av ' + totalPages + '... (' + scanTotals.scanned + ' produkter skannet, ' + scanTotals.found + ' kategorier funnet)')
			.removeClass('success error');

		return $.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: { action: 'vikingbad_scan_page', nonce: nonce, page: page },
			timeout: 600000
		}).then(function(r) {
			if (!r.success) {
				throw new Error(r.data.message || 'Unknown error');
			}
			scanTotals.scanned += r.data.scanned;
			scanTotals.found = r.data.found;

			if (page < totalPages) {
				return scanPage(page + 1, totalPages);
			}
		});
	}

	function runScan(fresh) {
		var $btn    = $('#vikingbad-scan-categories');
		var $result = $('#vikingbad-scan-result');

		$btn.prop('disabled', true);
		$('#vikingbad-scan-resume').hide();
		scanTotals = { scanned: 0, found: 0 };
		$result.text('Starter skanning...').removeClass('success error');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: { action: 'vikingbad_start_scan', nonce: nonce, fresh: fresh ? 1 : 0 },
			timeout: 600000
		})
		.then(function(r) {
			if (!r.success) {
				throw new Error(r.data.message || 'Unknown error');
			}

			var totalPages = r.data.total_pages;

			if (r.data.resuming) {
				// Resuming — pick up accumulated totals.
				scanTotals.scanned = r.data.scanned;
				scanTotals.found = r.data.found;
				var resumePage = r.data.resume_page;

				if (resumePage <= totalPages) {
					$result.text('Fortsetter fra side ' + resumePage + ' av ' + totalPages + '...');
					return scanPage(resumePage, totalPages);
				}
				// Already done.
				return;
			}

			// Fresh scan — page 1 already processed.
			scanTotals.scanned = r.data.scanned;
			scanTotals.found = r.data.found;

			if (totalPages > 1) {
				return scanPage(2, totalPages);
			}
		})
		.then(function() {
			$result.text('Ferdig! Skannet ' + scanTotals.scanned + ' produkter, fant ' + scanTotals.found + ' kategorier.').addClass('success');
			location.reload();
		})
		.fail(function(jqXHR, textStatus, err) {
			var msg = (err && err.message) ? err.message : textStatus;
			$result.text('Skanning feilet ved ' + scanTotals.scanned + ' produkter. Klikk "Skann API-kategorier" for a fortsette. (' + msg + ')').addClass('error');
		})
		.always(function() { $btn.prop('disabled', false); });
	}

	$('#vikingbad-scan-categories').on('click', function() {
		runScan(false);
	});

	$('#vikingbad-scan-fresh').on('click', function() {
		if (confirm('Dette vil forkaste tidligere funne kategorier og starte pa nytt. Fortsette?')) {
			runScan(true);
		}
	});

	// Save Category Mapping.
	$('#vikingbad-save-category-map').on('click', function() {
		var $btn    = $(this);
		var $result = $('#vikingbad-map-result');
		var map     = {};

		$('.vikingbad-cat-select').each(function() {
			var apiName = $(this).attr('name').replace('category_map[', '').replace(']', '');
			var termId  = $(this).val();
			if (termId) {
				map[apiName] = termId;
			}
		});

		$btn.prop('disabled', true);
		$result.text('Lagrer...').removeClass('success error');

		$.post(ajaxUrl, { action: 'vikingbad_save_category_map', nonce: nonce, category_map: map })
		.done(function(r) {
			if (r.success) {
				$result.text(r.data.message).addClass('success');
			} else {
				$result.text(r.data.message).addClass('error');
			}
		})
		.fail(function() {
			$result.text('Foresporselen feilet.').addClass('error');
		})
		.always(function() { $btn.prop('disabled', false); });
	});
});
</script>
