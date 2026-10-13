<?php
/**
 * Builds the Perfect Bite (Kings Eatz) sausage shop inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Product photos are read from /wordpress/kz-images.
 *
 * Packs, sizes, prices and the Nairobi delivery fee are Elvis's own, from the Perfect Bite order form
 * linked in his Instagram bio (13 Oct 2026). Stock levels and the batch day are demo values (PLACEHOLDER):
 * the owner sets them in WooCommerce → Products → Inventory and WooCommerce → Batch drops.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function kz_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'kz_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

if ( defined( 'KZ_FAST' ) && KZ_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings. Stock is managed: each pack has a batch count, and sold-out packs take pre-orders.
foreach ( [
	'blogname'                               => 'Perfect Bite by Kings Eatz',
	'blogdescription'                        => 'Real meat. Real smoke. Real flavour. Small-batch smoked sausages by Elvis Kahwai, delivered across Nairobi.',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Nairobi',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '8',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_stock_format'               => 'no_amount',
	'woocommerce_hold_stock_minutes'         => '30',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'specific',
	'woocommerce_specific_ship_to_countries' => [ 'KE' ],
	'woocommerce_ship_to_destination'        => 'billing_only',
	'woocommerce_shipping_cost_requires_address' => 'no',
	'woocommerce_enable_shipping_calc'       => 'no',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_checkout_address_2_field'   => 'required',
	'woocommerce_enable_coupons'             => 'no',
	// Batch drops (WooCommerce → Batch drops). 5 = Friday. PLACEHOLDER: confirm his batch and delivery days.
	'kz_batch_day'                           => '5',
	'kz_batch_note'                          => 'Each batch takes two days: minced, spiced, stuffed and smoked by hand.',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// One section for now; more (cuts, rubs, bundles) can be added in the admin when he makes them.
$t   = term_exists( 'sausages', 'product_cat' ) ?: wp_insert_term( 'Smoked Sausages', 'product_cat', [ 'slug' => 'sausages', 'description' => 'Choma-style smoked pork sausages, 800g+ packs of 9–10, made in small batches.' ] );
$cat = (int) $t['term_id'];
update_term_meta( $cat, 'order', 0 );

// Flavour tags (the shop filters).
$tags = [];
foreach ( [ 'spicy' => 'Spicy', 'sweet' => 'Sweet heat', 'cheesy' => 'Cheesy', 'original' => 'The original' ] as $slug => $name ) {
	$tt            = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$tags[ $slug ] = (int) $tt['term_id'];
}

// Payment: nothing is charged online. His M-Pesa till isn't public yet (PLACEHOLDER).
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay on delivery (M-Pesa or cash)',
	'description'        => 'Pay when the rider hands over your packs, by M-Pesa or cash.',
	'instructions'       => 'We confirm your order and delivery day by Instagram DM or phone. Pay by M-Pesa or cash when it reaches you.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_bacs_settings', [
	'enabled'         => 'yes',
	'title'           => 'Pay now by M-Pesa',
	'description'     => 'We send our M-Pesa till number with your confirmation. Paying ahead locks your packs in this batch.',
	'instructions'    => 'We will send our M-Pesa till number with your confirmation.',
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );
update_option( 'woocommerce_gateway_order', [ 'cod' => 0, 'bacs' => 1 ] );

// Delivery: KSh 200 anywhere in Nairobi (his order form).
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->set_zone_order( 1 );
$zone->add_location( 'KE', 'country' );
$zone->save();
$iid = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $iid . '_settings', [ 'title' => 'Doorstep delivery – Nairobi', 'tax_status' => 'none', 'cost' => '200' ] );

// The packs. heat: 0 none, 1 gentle, 2 medium, 3 hot. stock: this batch (PLACEHOLDER demo values).
$products = [
	[
		'slug' => 'classic-sausage', 'name' => 'Classic Sausage', 'price' => 1100, 'stock' => 18, 'heat' => 0, 'tags' => [ 'original' ],
		'tagline' => 'The original', 'emoji' => '🌭', 'badge' => 'Where it started',
		'short' => 'The one that started it all: 100% meat, proper spices and real smoke. Big, juicy, choma-style.',
		'desc' => 'Our original. Pork minced and seasoned by hand, stuffed into big choma-style links and smoked low and slow in our wooden smokehouse. No fillers, no mystery meat: just meat, proper spices and smoke. Throw them on the jiko, pan-fry them for breakfast or slice them into a stew.',
		'flavour' => 'Savoury, smoky and well seasoned: the sausage you remember from after school, done properly.',
		'pairs' => [ 'Ugali & kachumbari', 'A toasted bun with mustard', 'Sausage bean stew' ],
		'reel' => 'https://www.instagram.com/reel/DcX98N_OmJH/',
	],
	[
		'slug' => 'chilli-honey-heat', 'name' => 'Chilli Honey Heat', 'price' => 1100, 'stock' => 6, 'heat' => 2, 'tags' => [ 'spicy', 'sweet' ],
		'tagline' => 'Sweet, then the kick', 'emoji' => '🌶️🍯', 'badge' => 'Customer favourite', 'featured' => true,
		'short' => 'Honey up front, chilli on the finish. The flavour our customers keep posting about.',
		'desc' => 'Our Classic, with honey and chilli worked through the meat. The honey caramelises on the grill and the chilli kicks in after: sweet, smoky and spicy in the same bite. The one customers keep sharing on their stories.',
		'flavour' => 'Sweet honey first, a building chilli warmth after. Spicy, but friendly.',
		'pairs' => [ 'A hotdog with coleslaw', 'Salads (ask @africanbarb)', 'Cold drinks and good company' ],
		'reel' => 'https://www.instagram.com/reel/DdwTYHhKj-O/',
	],
	[
		'slug' => 'cheese-onion-sausage', 'name' => 'Cheese & Caramelised Onion', 'price' => 1200, 'stock' => 0, 'heat' => 0, 'tags' => [ 'cheesy' ],
		'tagline' => 'Melty and rich', 'emoji' => '🧀🧅',
		'short' => 'Cheese and slow-caramelised onion through every link. Melty, rich and sweet-savoury.',
		'desc' => 'Onions cooked down slowly until sweet and golden, mixed through the meat with cheese, then stuffed and smoked. Grill them and the cheese melts into the meat; slice one open and you will see why it is our premium pack.',
		'flavour' => 'Rich and savoury with sweet onion and pockets of melted cheese.',
		'pairs' => [ 'Breakfast with eggs and toast', 'Loaded fries', 'A festive bun' ],
		'reel' => 'https://www.instagram.com/reel/DdwTYHhKj-O/',
	],
];

function kz_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/kz-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = kz_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

foreach ( $products as $order => $d ) {
	$p = new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['short'] );
	$p->set_category_ids( [ $cat ] );
	$p->set_tag_ids( array_map( fn( $t ) => $tags[ $t ], $d['tags'] ) );
	$p->set_featured( ! empty( $d['featured'] ) );
	$p->set_regular_price( (string) $d['price'] );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $d['stock'] );
	$p->set_low_stock_amount( 8 );
	$p->set_backorders( 'notify' ); // sold out = pre-order for the next batch
	$p->set_weight( '0.8' );
	foreach ( [
		'heat'    => (string) $d['heat'],
		'tagline' => $d['tagline'],
		'emoji'   => $d['emoji'],
		'badge'   => $d['badge'] ?? '',
		'flavour' => $d['flavour'],
		'pairs'   => implode( "\n", $d['pairs'] ),
		'reel'    => $d['reel'],
	] as $k => $v ) {
		if ( $v !== '' ) $p->update_meta_data( '_kz_' . $k, $v );
	}
	$pid  = $p->save();
	$imgs = kz_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}
}

// Pages. Shop, recipes, story and FAQs are theme templates; these give them their addresses.
function kz_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
kz_page( 'order', 'Order fresh sausages', "<!-- wp:paragraph -->\n<p>Three flavours, 800g+ packs of 9–10 big choma-style sausages, smoked in small batches. Build your box, check out in under a minute, and we deliver anywhere in Nairobi for KSh 200.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[kz_shop]\n<!-- /wp:shortcode -->" );
kz_page( 'recipes', 'Recipes', '' );     // templates/page-recipes.html
kz_page( 'our-story', 'Our story', '' ); // templates/page-our-story.html
kz_page( 'faqs', 'FAQs & ordering guide', '' ); // templates/page-faqs.html
kz_page( 'party-orders', 'BBQ & party orders', "<!-- wp:paragraph -->\n<p>Weekend nyama choma, a birthday, an office braai or a wedding after-party: tell us the date, how many people and the flavours, and we set packs aside from the batch for you. Bigger orders need a few days' notice.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[kz_party_form]\n<!-- /wp:shortcode -->" );

foreach ( [ 'shop' => 'All sausages', 'cart' => 'Your box', 'checkout' => 'Checkout' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
