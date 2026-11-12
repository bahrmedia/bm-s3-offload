<?php
/**
 * Prepares further plugins for the integration tests (run with wp eval-file):
 * Polylang with two languages and media translation.
 *
 * @package BahrMedia\S3Offload
 */

if ( function_exists( 'PLL' ) ) {
	$bms3o_languages = array(
		array( 'English', 'en', 'en_US' ),
		array( 'Deutsch', 'de', 'de_DE' ),
	);
	foreach ( $bms3o_languages as $bms3o_order => $bms3o_language ) {
		if ( ! PLL()->model->get_language( $bms3o_language[1] ) ) {
			PLL()->model->languages->add(
				array(
					'name'       => $bms3o_language[0],
					'slug'       => $bms3o_language[1],
					'locale'     => $bms3o_language[2],
					'rtl'        => false,
					'term_group' => $bms3o_order,
				)
			);
		}
	}
	$bms3o_options                  = get_option( 'polylang' );
	$bms3o_options['media_support'] = true;
	update_option( 'polylang', $bms3o_options );
}
