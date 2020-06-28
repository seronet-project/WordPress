<?php
/**
 * REST API: WP_REST_Themes_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 5.0.0
 */

/**
 * Core class used to manage themes via the REST API.
 *
 * @since 5.0.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Themes_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 5.0.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'themes';
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @since 5.0.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to read the theme.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access for the item, otherwise WP_Error object.
	 */
	public function get_items_permissions_check( $request ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if ( current_user_can( $post_type->cap->edit_posts ) ) {
				return true;
			}
		}

		return new WP_Error(
			'rest_user_cannot_view',
			__( 'Sorry, you are not allowed to view themes.' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Retrieves a collection of themes.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		// Retrieve the list of registered collection query parameters.
		$registered = $this->get_collection_params();
		$themes     = array();

		if ( isset( $registered['status'], $request['status'] ) && in_array( 'active', $request['status'], true ) ) {
			$active_theme = wp_get_theme();
			$active_theme = $this->prepare_item_for_response( $active_theme, $request );
			$themes[]     = $this->prepare_response_for_collection( $active_theme );
		}

		$response = rest_ensure_response( $themes );

		$response->header( 'X-WP-Total', count( $themes ) );
		$response->header( 'X-WP-TotalPages', count( $themes ) );

		return $response;
	}

	/**
	 * Prepares a single theme output for response.
	 *
	 * @since 5.0.0
	 *
	 * @param WP_Theme        $theme   Theme object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $theme, $request ) {
		$data   = array();
		$fields = $this->get_fields_for_response( $request );

		if ( rest_is_field_included( 'stylesheet', $fields ) ) {
			$data['stylesheet'] = $theme->get_stylesheet();
		}

		if ( rest_is_field_included( 'template', $fields ) ) {
			/**
			 * Use the get_template() method, not the 'Template' header, for finding the template.
			 * The 'Template' header is only good for what was written in the style.css, while
			 * get_template() takes into account where WordPress actually located the theme and
			 * whether it is actually valid.
			 */
			$data['template'] = $theme->get_template();
		}

		$plain_field_mappings = array(
			'requires_php' => 'RequiresPHP',
			'requires_wp'  => 'RequiresWP',
			'textdomain'   => 'TextDomain',
			'version'      => 'Version',
		);

		foreach ( $plain_field_mappings as $field => $header ) {
			if ( rest_is_field_included( $field, $fields ) ) {
				$data[ $field ] = $theme->get( $header );
			}
		}

		if ( rest_is_field_included( 'screenshot', $fields ) ) {
			// Using $theme->get_screenshot() with no args to get absolute URL.
			$data['screenshot'] = $theme->get_screenshot() ? $theme->get_screenshot() : '';
		}

		$rich_field_mappings = array(
			'author'      => 'Author',
			'author_uri'  => 'AuthorURI',
			'description' => 'Description',
			'name'        => 'Name',
			'tags'        => 'Tags',
			'theme_uri'   => 'ThemeURI',
		);

		foreach ( $rich_field_mappings as $field => $header ) {
			if ( rest_is_field_included( "{$field}.raw", $fields ) ) {
				$data[ $field ]['raw'] = $theme->display( $header, false, true );
			}

			if ( rest_is_field_included( "{$field}.rendered", $fields ) ) {
				$data[ $field ]['rendered'] = $theme->display( $header );
			}
		}

		if ( rest_is_field_included( 'theme_supports', $fields ) ) {
			foreach ( get_registered_theme_features() as $feature => $config ) {
				if ( ! is_array( $config['show_in_rest'] ) ) {
					continue;
				}

				$name = $config['show_in_rest']['name'];

				if ( ! rest_is_field_included( "theme_supports.{$name}", $fields ) ) {
					continue;
				}

				if ( ! current_theme_supports( $feature ) ) {
					$data['theme_supports'][ $name ] = $config['show_in_rest']['schema']['default'];
					continue;
				}

				$support = get_theme_support( $feature );

				if ( isset( $config['show_in_rest']['prepare_callback'] ) ) {
					$prepare = $config['show_in_rest']['prepare_callback'];
				} else {
					$prepare = array( $this, 'prepare_theme_support' );
				}

				$prepared = $prepare( $support, $config, $feature, $request );

				if ( is_wp_error( $prepared ) ) {
					continue;
				}

				$data['theme_supports'][ $name ] = $prepared;
			}
		}

		$data = $this->add_additional_fields_to_object( $data, $request );

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );

		/**
		 * Filters theme data returned from the REST API.
		 *
		 * @since 5.0.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WP_Theme         $theme    Theme object used to create response.
		 * @param WP_REST_Request  $request  Request object.
		 */
		return apply_filters( 'rest_prepare_theme', $response, $theme, $request );
	}

	/**
	 * Prepares the theme support value for inclusion in the REST API response.
	 *
	 * @since 5.5.0
	 *
	 * @param mixed           $support The raw value from get_theme_support().
	 * @param array           $args    The feature's registration args.
	 * @param string          $feature The feature name.
	 * @param WP_REST_Request $request The request object.
	 * @return mixed The prepared support value.
	 */
	protected function prepare_theme_support( $support, $args, $feature, $request ) {
		$schema = $args['show_in_rest']['schema'];

		if ( 'boolean' === $schema['type'] ) {
			return true;
		}

		if ( is_array( $support ) ) {
			if ( ! $args['variadic'] ) {
				$support = $support[0];
			}

			// Multi-type theme-support schema definitions always list boolean first.
			if ( is_array( $schema['type'] ) && 'boolean' === $schema['type'][0] ) {
				// Pass the non-boolean type through to the sanitizer, which cannot itself
				// determine the intended type if the value is invalid (for example if an
				// object includes non-safelisted properties). See #50300.
				$schema['type'] = $schema['type'][1];
			}
		}

		return rest_sanitize_value_from_schema( $support, $schema );
	}

	/**
	 * Retrieves the theme's schema, conforming to JSON Schema.
	 *
	 * @since 5.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'theme',
			'type'       => 'object',
			'properties' => array(
				'stylesheet'     => array(
					'description' => __( 'The theme\'s stylesheet. This uniquely identifies the theme.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'template'       => array(
					'description' => __( 'The theme\'s template. If this is a child theme, this refers to the parent theme, otherwise this is the same as the theme\'s stylesheet.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'author'         => array(
					'description' => __( 'The theme author.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The theme author\'s name, as found in the theme header.' ),
							'type'        => 'string',
						),
						'rendered' => array(
							'description' => __( 'HTML for the theme author, transformed for display.' ),
							'type'        => 'string',
						),
					),
				),
				'author_uri'     => array(
					'description' => __( 'The website of the theme author.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The website of the theme author, as found in the theme header.' ),
							'type'        => 'string',
							'format'      => 'uri',
						),
						'rendered' => array(
							'description' => __( 'The website of the theme author, transformed for display.' ),
							'type'        => 'string',
							'format'      => 'uri',
						),
					),
				),
				'description'    => array(
					'description' => __( 'A description of the theme.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The theme description, as found in the theme header.' ),
							'type'        => 'string',
						),
						'rendered' => array(
							'description' => __( 'The theme description, transformed for display.' ),
							'type'        => 'string',
						),
					),
				),
				'name'           => array(
					'description' => __( 'The name of the theme.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The theme name, as found in the theme header.' ),
							'type'        => 'string',
						),
						'rendered' => array(
							'description' => __( 'The theme name, transformed for display.' ),
							'type'        => 'string',
						),
					),
				),
				'requires_php'   => array(
					'description' => __( 'The minimum PHP version required for the theme to work.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'requires_wp'    => array(
					'description' => __( 'The minimum WordPress version required for the theme to work.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'screenshot'     => array(
					'description' => __( 'The theme\'s screenshot URL.' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'tags'           => array(
					'description' => __( 'Tags indicating styles and features of the theme.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The theme tags, as found in the theme header.' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
							),
						),
						'rendered' => array(
							'description' => __( 'The theme tags, transformed for display.' ),
							'type'        => 'string',
						),
					),
				),
				'textdomain'     => array(
					'description' => __( 'The theme\'s textdomain.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'theme_supports' => array(
					'description' => __( 'Features supported by this theme.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(),
				),
				'theme_uri'      => array(
					'description' => __( 'The URI of the theme\'s webpage.' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'The URI of the theme\'s webpage, as found in the theme header.' ),
							'type'        => 'string',
							'format'      => 'uri',
						),
						'rendered' => array(
							'description' => __( 'The URI of the theme\'s webpage, transformed for display.' ),
							'type'        => 'string',
							'format'      => 'uri',
						),
					),
				),
				'version'        => array(
					'description' => __( 'The theme\'s current version.' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);

		foreach ( get_registered_theme_features() as $feature => $config ) {
			if ( ! is_array( $config['show_in_rest'] ) ) {
				continue;
			}

			$name = $config['show_in_rest']['name'];

			$schema['properties']['theme_supports']['properties'][ $name ] = $config['show_in_rest']['schema'];
		}

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Retrieves the search params for the themes collection.
	 *
	 * @since 5.0.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$query_params = parent::get_collection_params();

		$query_params['status'] = array(
			'description'       => __( 'Limit result set to themes assigned one or more statuses.' ),
			'type'              => 'array',
			'items'             => array(
				'enum' => array( 'active' ),
				'type' => 'string',
			),
			'required'          => true,
			'sanitize_callback' => array( $this, 'sanitize_theme_status' ),
		);

		/**
		 * Filter collection parameters for the themes controller.
		 *
		 * @since 5.0.0
		 *
		 * @param array $query_params JSON Schema-formatted collection parameters.
		 */
		return apply_filters( 'rest_themes_collection_params', $query_params );
	}

	/**
	 * Sanitizes and validates the list of theme status.
	 *
	 * @since 5.0.0
	 *
	 * @param string|array    $statuses  One or more theme statuses.
	 * @param WP_REST_Request $request   Full details about the request.
	 * @param string          $parameter Additional parameter to pass to validation.
	 * @return array|WP_Error A list of valid statuses, otherwise WP_Error object.
	 */
	public function sanitize_theme_status( $statuses, $request, $parameter ) {
		$statuses = wp_parse_slug_list( $statuses );

		foreach ( $statuses as $status ) {
			$result = rest_validate_request_arg( $status, $request, $parameter );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $statuses;
	}
}
