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
			$bms3o_result = PLL()->model->languages->add(
				array(
					'name'       => $bms3o_language[0],
					'slug'       => $bms3o_language[1],
					'locale'     => $bms3o_language[2],
					'rtl'        => false,
					'term_group' => $bms3o_order,
				)
			);
			echo 'Polylang language ' . esc_html( $bms3o_language[1] ) . ': ' . ( is_wp_error( $bms3o_result ) ? esc_html( $bms3o_result->get_error_message() ) : 'added' ) . "\n";
		}
	}
	// Through Polylang's options object: it writes its values back at the end of the request.
	PLL()->options['media_support'] = true;
	if ( method_exists( PLL()->options, 'save' ) ) {
		PLL()->options->save();
	}
	echo 'Polylang languages: ' . esc_html( implode( ',', wp_list_pluck( PLL()->model->get_languages_list(), 'slug' ) ) ) . ', media translation: ' . ( PLL()->options['media_support'] ? 'on' : 'off' ) . "\n";
}
