<?php
/*
  Plugin Name: Climate Tagger
  Description: Recommends tags in a tag cloud based on Climate Tagger API.
  Version: 1.0.2
  Author: Aptivate
*/

if ( is_dir( WPMU_PLUGIN_DIR . '/climate-tagger' ) ) {
	define( 'CLIMATE_TAGGER_INCLUDES', WPMU_PLUGIN_URL . '/climate-tagger' );
} else {
	define( 'CLIMATE_TAGGER_INCLUDES', WP_PLUGIN_URL . '/climate-tagger' );
}

// Documentation: http://api.climatetagger.net/documentation/
define('CLIMATE_TAGGER_API_URL', 'http://api.climatetagger.net');

class ClimateTagger {

	function admin_menu() {
		add_options_page(
			'Climate Tagger',
			'Climate Tagger',
			'manage_options',
			'climate-tagger',
			array( 'ClimateTagger', 'add_options_page_callback' )
		);

	}

	function admin_init() {
		self::set_defaults();

		register_setting(
			'climate_tagger_general_settings',
			'climate_tagger_general_settings'
		);

		// Add an action link pointing to the settings page.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array(
			'ClimateTagger',
			'add_action_links',
		) );
	}

	function set_defaults() {
		$options = get_option( 'climate_tagger_general_settings' );

		$options = wp_parse_args(
			$options,
			array(
				'token'      => '',
				'limit'      => '20',
				'post_types' => 'post',
				'project'    => 'default',
			)
		);

		update_option( 'climate_tagger_general_settings', $options );
	}

	function add_action_links( $links ) {
		return array_merge(
			array(
				'settings' => '<a href="' . admin_url( 'options-general.php?page=climate-tagger' ) . '">Settings</a>',
			),
			$links
		);
	}

	function add_options_page_callback() {
		?>
		<div class="wrap">
			<h2>Climate Tagger by Aptivate</h2>

			<div>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'climate_tagger_general_settings' );
					$options = get_option( 'climate_tagger_general_settings' );
					?>

					<h3>General Settings</h3>

					<table class="form-table">
						<tr valign="top">
							<th scope="row">Authentication token:</th>
							<td>
								<?php
								printf(
									'<input type="text" id="climate-tagger-token" name="climate_tagger_general_settings[token]" value="%s" size="50" />',
									esc_attr( $options['token'] )
								);
								echo '<br /><span class="description">A valid authentication token that has been generated in the Climate Tagger API dashboard. <a href="http://api.climatetagger.net/register/" target="_blank">http://api.climatetagger.net/register</a></span>';
								?>
							</td>
						</tr>

						<tr valign="top">
							<th scope="row">Climate Thesaurus:</th>
							<td>
								<?php
								$projects = self::get_climate_tagger_projects();
								if ( empty( $projects ) ) {
									echo 'Please check your authentication token above and click the "Save Changes" button before you can select a Climate Thesaurus.';
								} else {
									echo '<select id="climate-tagger-project" name="climate_tagger_general_settings[project]">';
									foreach ( $projects as $key => $project ) {
										$selected = ( $key == $options['project'] ) ? 'selected="selected"' : '';
										echo '<option value="' . $key . '" ' . $selected . '>' . $project['label'] . '</option>';
									}
									echo '</select>';
									echo '<br/><span class="description">Select one of the Climate Thesauri.</span>';
								}
								?>
							</td>
						</tr>

						<tr valign="top">
							<th scope="row">Post types:</th>
							<td>
								<?php
								printf(
									'<input type="text" id="climate-tagger-post-types" name="climate_tagger_general_settings[post_types]" value="%s" />',
									esc_attr( $options['post_types'] )
								);
								echo '<br /><span class="description">Supported post types, separated by commas.</span>';
								?>
							</td>
						</tr>

						<tr valign="top">
							<th scope="row">Maximum number of tags:</th>
							<td>
								<?php
								printf(
									'<input type="text" id="climate-tagger-limit" name="climate_tagger_general_settings[limit]" value="%s" size="5" />',
									esc_attr( $options['limit'] )
								);
								echo '<br /><span class="description">Maximum number of tags to retrieve from the Climate Tagger API and display in the word cloud.</span>';
								?>
							</td>
						</tr>

					</table>

					<?php
					submit_button();
					?>

				</form>
			</div>
		</div>
		<?php
	}

	function add_box() {
		$options = get_option( 'climate_tagger_general_settings' );

		$post_types = explode( ',', $options['post_types'] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'boxid',
				'Suggested Tags (Climate Tagger)',
				array( 'ClimateTagger', 'box_routine' ),
				trim( $post_type ),
				'side',
				'low'
			);
		}
	}

	function box_routine() {
		$response = self::get_climate_tagger_response();

		if ( is_wp_error( $response ) ) {
			echo $response->get_error_message();

			return;
		}

		if ( $response['response']['code'] != 200 ) {
			echo $response['body'];

			return;
		}

		$tags_post = self::get_tags_from_response( $response );
		if ( count( $tags_post ) == 0 ) {
			echo "Click 'Save Draft' to refresh tag suggestions.";

			return;
		}

		self::print_tag_cloud( $tags_post );
	}

	function print_tag_cloud( $tags_rec ) {
		arsort( $tags_rec );

		$min_size = 10;
		$max_size = 24;

		$minimum_strength = min( array_values( $tags_rec ) );
		$maximum_strength = max( array_values( $tags_rec ) );

		$spread = $maximum_strength - $minimum_strength;
		if ( $spread == 0 ) {
			$spread = 1;
		}

		$step = ( $max_size - $min_size ) / $spread;

		foreach ( $tags_rec as $tag_name => $tag_strength ) {
			$size = $min_size + ( $tag_strength - $minimum_strength ) * $step;
			?>
			<a href="#" style="font-size: <?php echo "$size" ?>pt;"
			   onClick="tag_add('<?php echo $tag_name; ?>');return false;"><?php echo "$tag_name" ?></a>
			<?php
		}

		echo '&nbsp;&nbsp;&nbsp;';
	}

	function get_climate_tagger_projects() {
		$options = get_option( 'climate_tagger_general_settings' );
		$url     = CLIMATE_TAGGER_API_URL . '/service/projects';
		$url     = $url . '?token=' . $options['token'];

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( $response['response']['code'] != 200 ) {
			return array();
		}

		$projects = self::get_projects_from_response( $response );
		if ( count( $projects ) == 0 ) {
			return array();
		}

		return $projects;
	}

	function get_projects_from_response( $response ) {
		$result   = json_decode( $response['body'], TRUE );
		$projects = array();

		if ( ! empty( $result ) ) {
			foreach ( $result as $project ) {
				$key = self::create_machine_name_from_label( $project['title'] );
				if ( $key == 'reegle_api_thesaurus' ) {
					$key = 'default';
					$project['title'] = 'Full Climate Thesaurus (default)';
				}
				$projects[ $key ] = array(
					'label' => $project['title'],
					'uuid'  => $project['id'],
				);
			}
		}

		return $projects;
	}

	function get_climate_tagger_response() {
		global $post;

		$content = $post->post_title . ' ' . $post->post_content;

		$content = apply_filters(
			'climate-tagger-content',
			$content,
			$post );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$url      = CLIMATE_TAGGER_API_URL . '/service/extract';
		$options  = get_option( 'climate_tagger_general_settings' );
		$language = apply_filters( 'climate-tagger-language', 'en', $post );

		$fields = array(
			'text'          => $content,
			'locale'        => $language,
			'format'        => 'json',
			'token'         => $options['token'],
			'countConcepts' => $options['limit'],
		);

		$projects = array();
		if ( 'default' != $options['project'] ) {
			$projects = self::get_climate_tagger_projects();
		}
		if ( ! empty( $projects ) && isset( $options['project'] ) ) {
			$fields['projectId'] = $projects[ $options['project'] ]['uuid'];
		}

		return wp_remote_post( $url, array( 'body' => $fields ) );
	}

	function get_tags_from_response( $response ) {
		$result = json_decode( $response['body'], TRUE );

		$concepts = $result['concepts'];

		$tags = array();

		foreach ( $concepts as $concept ) {
			$tags[ $concept['prefLabel'] ] = $concept['score'];
		}

		return $tags;
	}

	function admin_add_my_script() {
		wp_enqueue_script(
			'climate-tagger-add-tag',
			CLIMATE_TAGGER_INCLUDES . '/climate-tagger-add-tag.js',
			array( 'jquery' )
		);
	}

	function create_machine_name_from_label( $label ) {
		$label = strtolower( trim( $label ) );

		return preg_replace( '/[^a-z0-9_]+/', '_', $label );
	}
}

if ( is_admin() ) {
	add_action( 'admin_menu', array( 'ClimateTagger', 'admin_menu' ) );
	add_action( 'admin_init', array( 'ClimateTagger', 'admin_init' ) );
	add_action( 'admin_menu', array( 'ClimateTagger', 'add_box' ) );
	add_action( 'admin_print_scripts', array( 'ClimateTagger', 'admin_add_my_script' ) );
}
?>
