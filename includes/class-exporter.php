<?php
/**
 * Exporter Engine.
 *
 * @package HivePress_Import_PB
 */

namespace HivePress_Import_PB;

use HivePress\Models;
use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Exporter
 *
 * Generates and downloads a clean CSV export of HivePress listings.
 */
class Exporter {

	/**
	 * Export listings matching criteria into a CSV stream download.
	 *
	 * @param array $args
	 */
	public static function export( $args = [] ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'pb-hivepress-import' ) );
		}

		$category_id = ! empty( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		$delimiter   = ! empty( $args['delimiter'] ) ? $args['delimiter'] : ',';

		$query = Models\Listing::query();
		if ( $category_id ) {
			$query->filter( [ 'categories__in' => [ $category_id ] ] );
		}

		$listings = $query->get();

		$headers = [
			'ID',
			'Title',
			'Description',
			'Categories',
			'Images',
			'Author',
			'Featured',
			'Verified',
			'Status',
		];

		// Collect custom attributes
		$sample_listing = new Models\Listing();
		if ( $category_id ) {
			$sample_listing->set_categories( [ $category_id ] );
		}

		$form_fields = ( new \HivePress\Forms\Listing_Update( [ 'model' => $sample_listing ] ) )->get_fields();
		$custom_keys = [];

		foreach ( $form_fields as $key => $field ) {
			if ( ! in_array( $key, [ 'title', 'description', 'id', 'user', 'images', 'categories', 'featured', 'verified', 'status' ], true ) && $field->get_label() ) {
				$custom_keys[ $key ] = $field->get_label();
				$headers[] = $field->get_label();
			}
		}

		$filename = 'listings-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		// Write UTF-8 BOM for Excel
		fputs( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, $headers, $delimiter );

		foreach ( $listings as $listing ) {
			// Categories
			$cat_names = [];
			$terms = wp_get_post_terms( $listing->get_id(), 'hp_listing_category' );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$cat_names[] = $term->name;
				}
			}

			// Images
			$image_urls = [];
			$img_ids = (array) $listing->get_images__id();
			foreach ( $img_ids as $img_id ) {
				$url = wp_get_attachment_url( $img_id );
				if ( $url ) {
					$image_urls[] = $url;
				}
			}

			// Author
			$author_user = get_userdata( $listing->get_user__id() );
			$author_name = $author_user ? $author_user->user_email : '';

			$row = [
				$listing->get_id(),
				$listing->get_title(),
				$listing->get_description(),
				implode( ', ', $cat_names ),
				implode( ', ', $image_urls ),
				$author_name,
				$listing->get_featured() ? 1 : 0,
				$listing->get_verified() ? 1 : 0,
				$listing->get_status(),
			];

			// Custom attributes
			foreach ( array_keys( $custom_keys ) as $attr_key ) {
				$val = $listing->get_value( $attr_key );
				if ( is_array( $val ) ) {
					$row[] = implode( ', ', $val );
				} else {
					$row[] = $val;
				}
			}

			fputcsv( $output, $row, $delimiter );
		}

		fclose( $output );
		exit;
	}
}
