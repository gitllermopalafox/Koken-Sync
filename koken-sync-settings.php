<?php

$wpsf_settings[] = array(
	'section_id' => 'general',
	'section_title' => 'General',
	'section_description' => 'General settings for Koken Sync',
	'section_order' => 1,
	'fields' => array(
		array(
			'id' => 'koken_url',
			'title' => 'Koken URL',
			'desc' => 'The URL of your Koken application (no trailing slash).',
			'type' => 'text',
			'std' => ''
		)
	)
);