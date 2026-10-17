<?php
/**
 * Run the real Action Scheduler path instead of the integration suite's inline seam.
 *
 * @package SEOStudio
 */

define( 'SEOSTUDIO_JOBS_SYNC', false );
require dirname( __DIR__ ) . '/php/Integration/bootstrap.php';
