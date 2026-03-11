<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap vikingbad-import">
	<h1><?php esc_html_e( 'Vikingbad Produktimport', 'vikingbad' ); ?></h1>

	<div class="vikingbad-import-controls">
		<button type="button" id="vikingbad-start-import" class="button button-primary button-hero">
			<?php esc_html_e( 'Start import', 'vikingbad' ); ?>
		</button>
	</div>

	<div id="vikingbad-progress-wrap" class="vikingbad-progress-wrap" style="display:none;">
		<div class="vikingbad-progress-bar">
			<div id="vikingbad-progress-fill" class="vikingbad-progress-fill" style="width:0%"></div>
		</div>
		<p id="vikingbad-progress-text" class="vikingbad-progress-text"></p>
	</div>

	<div id="vikingbad-stats" class="vikingbad-stats" style="display:none;">
		<div class="vikingbad-stat vikingbad-stat-created">
			<span class="vikingbad-stat-label"><?php esc_html_e( 'Opprettet', 'vikingbad' ); ?></span>
			<span id="vikingbad-count-created" class="vikingbad-stat-value">0</span>
		</div>
		<div class="vikingbad-stat vikingbad-stat-updated">
			<span class="vikingbad-stat-label"><?php esc_html_e( 'Oppdatert', 'vikingbad' ); ?></span>
			<span id="vikingbad-count-updated" class="vikingbad-stat-value">0</span>
		</div>
		<div class="vikingbad-stat vikingbad-stat-failed">
			<span class="vikingbad-stat-label"><?php esc_html_e( 'Feilet', 'vikingbad' ); ?></span>
			<span id="vikingbad-count-failed" class="vikingbad-stat-value">0</span>
		</div>
		<div class="vikingbad-stat vikingbad-stat-skipped">
			<span class="vikingbad-stat-label"><?php esc_html_e( 'Hoppet over', 'vikingbad' ); ?></span>
			<span id="vikingbad-count-skipped" class="vikingbad-stat-value">0</span>
		</div>
	</div>

	<div id="vikingbad-skipped" class="vikingbad-skipped" style="display:none;">
		<h3><?php esc_html_e( 'Produkter hoppet over', 'vikingbad' ); ?> (<span id="vikingbad-skipped-count">0</span>)</h3>
		<p class="description"><?php esc_html_e( 'Disse produktene ble hoppet over fordi de ikke har EAN i APIet. De kan ikke hentes enkeltvis.', 'vikingbad' ); ?></p>
		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SKU', 'vikingbad' ); ?></th>
					<th><?php esc_html_e( 'Navn', 'vikingbad' ); ?></th>
					<th><?php esc_html_e( 'Beskrivelse', 'vikingbad' ); ?></th>
					<th><?php esc_html_e( 'Arsak', 'vikingbad' ); ?></th>
				</tr>
			</thead>
			<tbody id="vikingbad-skipped-list"></tbody>
		</table>
	</div>

	<div id="vikingbad-errors" class="vikingbad-errors" style="display:none;">
		<h3><?php esc_html_e( 'Feil', 'vikingbad' ); ?></h3>
		<ul id="vikingbad-error-list"></ul>
	</div>
</div>
