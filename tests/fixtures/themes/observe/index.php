<?php
/**
 * Front-end template with a header H1 outside the content root.
 *
 * @package SEOStudio
 */

$heading = apply_filters( 'seostudio_observe_h1', 'how to price a cleaning contract' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<?php wp_head(); ?>
</head>
<body>
<header><h1><?php echo esc_html( (string) $heading ); ?></h1></header>
<main>
<?php
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		the_content();
	}
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
