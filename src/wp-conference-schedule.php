<?php
/**
 * Forked from WP Conference Schedule by Road Warrior Creative.
 *
 * @link              https://wpconferenceschedule.com
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Conference Schedule
 * Plugin URI:        https://wpaccessibility.day
 * Description:       Generates people, sponsor, session post types & displays schedule information.
 * Version:           1.0.5.2
 * Author:            WP Accessibility Day
 * Author URI:        https://wpaccessibility.day
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wpa-conference
 *
 * @package           wpcsp
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Plugin directory.
define( 'WPCS_DIR', plugin_dir_path( __FILE__ ) );

// Version.
define( 'WPCS_VERSION', '1.0.5.2' );

// Plugin File URL.
define( 'PLUGIN_FILE_URL', __FILE__ );

// Includes.
require_once( WPCS_DIR . 'inc/post-types.php' );
require_once( WPCS_DIR . 'inc/taxonomies.php' );
require_once( WPCS_DIR . 'inc/schedule-output-functions.php' );
require_once( WPCS_DIR . 'inc/settings.php' );
require_once( WPCS_DIR . '/inc/activation.php' );
require_once( WPCS_DIR . '/inc/deactivation.php' );
require_once( WPCS_DIR . '/inc/uninstall.php' );
require_once( WPCS_DIR . '/inc/enqueue-scripts.php' );
require_once( WPCS_DIR . '/inc/cmb2/init.php' );
require_once( WPCS_DIR . '/inc/cmb-field-select2/cmb-field-select2.php' );
require_once( WPCS_DIR . '/inc/cmb2-conditional-logic/cmb2-conditional-logic.php' );

add_shortcode( 'schedule', 'wpcs_schedule' );
add_shortcode( 'donors', 'wpcs_display_donors', 10, 2 );
add_shortcode( 'microsponsors', 'wpcs_display_microsponsors', 10, 2 );
add_shortcode( 'attendees', 'wpcs_people' );
add_shortcode( 'able', 'wpcs_get_video' );
add_shortcode( 'wpad', 'wpcs_event_start' );

/**
 * Redirect low level sponsors singular pages.
 *
 * @return void
 */
function wpcs_redirect_sponsors() {
	if ( is_singular( 'wpcsp_sponsor' ) ) {
		if ( has_term( 'microsponsor', 'wpcsp_sponsor_level' ) || has_term( 'donor', 'wpcsp_sponsor_level' ) ) {
			wp_redirect( get_option( 'wpcsp_field_sponsors_page_url', home_url() ) );
			exit;
		}
	}
}
add_action( 'template_redirect', 'wpcs_redirect_sponsors' );

/**
 * The Conference Schedule output and meta.
 */
class WPCS_Conference_Schedule {

	/**
	 * Fired when plugin file is loaded.
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'wpcs_admin_init' ) );
		add_action( 'admin_print_styles', array( $this, 'wpcs_admin_css' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'wpcs_admin_enqueue_scripts' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'wpcs_admin_print_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wpcs_enqueue_scripts' ) );
		add_action( 'save_post', array( $this, 'wpcs_save_post_session' ), 10, 2 );
		add_action( 'manage_posts_custom_column', array( $this, 'wpcs_manage_post_types_columns_output' ), 10, 2 );
		add_action( 'cmb2_admin_init', array( $this, 'wpcs_session_metabox' ) );
		add_action( 'add_meta_boxes', array( $this, 'wpcs_add_meta_boxes' ) );
		add_filter( 'wpcs_filter_session_speaker_meta_field', array( $this, 'filter_session_speaker_meta_field' ), 11, 1 );
		add_shortcode( 'wpcs_sponsors', array( $this, 'shortcode_sponsors' ) );
		add_shortcode( 'wpcs_speakers', array( $this, 'shortcode_speakers' ) );
		add_filter( 'wpcs_filter_session_speakers', array( $this, 'filter_session_speakers' ), 11, 2 );
		add_filter( 'wpcs_session_content_header', array( $this, 'session_content_header' ), 11, 1 );
		add_action( 'wpsc_single_taxonomies', array( $this, 'single_session_tags' ) );
		add_filter( 'wpcs_filter_single_session_speakers', array( $this, 'filter_single_session_speakers' ), 11, 2 );
		add_filter( 'wpcs_session_content_footer', array( $this, 'session_sponsors' ), 11, 1 );
		add_filter( 'manage_wpcs_session_posts_columns', array( $this, 'wpcs_manage_post_types_columns' ) );
		add_filter( 'manage_edit-wpcs_session_sortable_columns', array( $this, 'wpcs_manage_sortable_columns' ) );
		add_filter( 'display_post_states', array( $this, 'wpcs_display_post_states' ) );
	}

	/**
	 * Runs during admin_init.
	 */
	public function wpcs_admin_init() {
		add_action( 'pre_get_posts', array( $this, 'wpcs_admin_pre_get_posts' ) );
	}

	/**
	 * Runs during pre_get_posts in admin.
	 *
	 * @param WP_Query $query The query.
	 */
	public function wpcs_admin_pre_get_posts( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$current_screen = get_current_screen();

		// Order by session time.
		if ( 'edit-wpcs_session' === $current_screen->id && $query->get( 'orderby' ) === '_wpcs_session_time' ) {
			$query->set( 'meta_key', '_wpcs_session_time' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @uses wp_enqueue_style()
	 * @uses wp_enqueue_script()
	 *
	 * @return void
	 */
	public function wpcs_admin_enqueue_scripts() {
		global $post_type;

		// Enqueues scripts and styles for session admin page.
		if ( 'wpcs_session' === $post_type ) {
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_register_style( 'jquery-ui', plugins_url( '/assets/css/jquery-ui.css', __FILE__ ) );

			wp_enqueue_style( 'jquery-ui' );
		}

	}

	/**
	 * Print JavaScript.
	 */
	public function wpcs_admin_print_scripts() {
		global $post_type;

		// DatePicker for Session posts.
		if ( 'wpcs_session' === $post_type ) :
			?>

			<script type="text/javascript">
				jQuery( document ).ready( function( $ ) {
					$( '#wpcs-session-date' ).datepicker( {
						dateFormat:  'yy-mm-dd',
						changeMonth: true,
						changeYear:  true
					} );
				} );
			</script>

			<?php
		endif;
	}

	/**
	 * Enqueue the scripts and styles.
	 *
	 * @uses wp_enqueue_style()
	 * @uses wp_enqueue_script()
	 */
	public function wpcs_enqueue_scripts() {
		wp_enqueue_style( 'wpcs_styles', plugins_url( '/assets/css/style.css', __FILE__ ), array(), 2 );

		wp_enqueue_style(
			'font-awesome',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta2/css/all.min.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script( 'wpcs_scripts', plugins_url( '/assets/js/conference-time-zones.js', __FILE__ ), array( 'jquery' ), WPCS_VERSION );
	}

	/**
	 * Runs during admin_print_styles, adds CSS for custom admin columns and block editor
	 *
	 * @uses wp_enqueue_style()
	 */
	public function wpcs_admin_css() {
		wp_enqueue_style( 'wpcs-admin', plugins_url( '/assets/css/admin.css', __FILE__ ), array(), 1 );
	}

	/**
	 * Update the session metadata.
	 *
	 * @return void
	 */
	public function wpcs_update_session_date_meta() {
		$post_id = null;
		if ( isset( $_REQUEST['post'] ) || isset( $_REQUEST['post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = empty( $_REQUEST['post_ID'] ) ? absint( $_REQUEST['post'] ) : absint( $_REQUEST['post_ID'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$session_date = get_post_meta( $post_id, '_wpcs_session_date', true );
		$session_time = get_post_meta( $post_id, '_wpcs_session_time', true );

		if ( $post_id && ! $session_date && $session_time ) {
			update_post_meta( $post_id, '_wpcs_session_date', $session_time );
		}
	}

	/**
	 * Session CMB metabox.
	 *
	 * @return void
	 */
	public function wpcs_session_metabox() {

		$cmb = new_cmb2_box(
			array(
				'id'           => 'wpcs_session_metabox',
				'title'        => __( 'Session Information', 'wpa-conference' ),
				'object_types' => array( 'wpcs_session' ), // Post type.
				'context'      => 'normal',
				'priority'     => 'high',
				'show_names'   => true, // Show field names on the left.
			)
		);

		// filter speaker meta field.
		if ( has_filter( 'wpcs_filter_session_speaker_meta_field' ) ) {
			/**
			 * Filter session speaker meta field.
			 *
			 * @hook wpcs_filter_session_speaker_meta_field
			 *
			 * @param {object} CMB2 generated metabox for speaker meta fields.
			 *
			 * @return {object}
			 */
			$cmb = apply_filters( 'wpcs_filter_session_speaker_meta_field', $cmb );
		} else {
			// Speaker Name(s).
			$cmb->add_field(
				array(
					'name' => __( 'Speaker Name(s)', 'wpa-conference' ),
					'id'   => '_wpcs_session_speakers',
					'type' => 'text',
				)
			);
		}

	}

	/**
	 * Fired during add_meta_boxes, adds extra meta boxes to our custom post types.
	 */
	public function wpcs_add_meta_boxes() {
		add_meta_box( 'session-info', __( 'Session Info', 'wpa-conference' ), array( $this, 'wpcs_metabox_session_info' ), 'wpcs_session', 'normal' );
	}

	/**
	 * Session info metabox.
	 *
	 * @return void
	 */
	public function wpcs_metabox_session_info() {
		$post             = get_post();
		$session_time     = absint( get_post_meta( $post->ID, '_wpcs_session_time', true ) );
		$default_date     = ( get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) ? gmdate( 'Y-m-d', get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) : gmdate( 'Y-m-d', strtotime( get_option( 'wpad_start_time' ) ) );
		$default_hours    = ( get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) ? gmdate( 'g', get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) : gmdate( 'g', strtotime( get_option( 'wpad_start_time' ) ) );
		$default_minutes  = ( get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) ? gmdate( 'i', get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) : gmdate( 'i', strtotime( get_option( 'wpad_start_time' ) ) );
		$default_meridiem = ( get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) ? gmdate( 'a', get_user_meta( wp_get_current_user()->ID, '_last_entered', true ) ) : gmdate( 'a', strtotime( get_option( 'wpad_start_time' ) ) );

		$session_date     = ( $session_time ) ? gmdate( 'Y-m-d', $session_time ) : $default_date;
		$session_hours    = ( $session_time ) ? gmdate( 'g', $session_time ) : $default_hours;
		$session_minutes  = ( $session_time ) ? gmdate( 'i', $session_time ) : $default_minutes;
		$session_meridiem = ( $session_time ) ? gmdate( 'a', $session_time ) : $default_meridiem;
		$session_type     = get_post_meta( $post->ID, '_wpcs_session_type', true );
		$session_speakers = get_post_meta( $post->ID, '_wpcs_session_speakers', true );
		$session_captions = get_post_meta( $post->ID, '_wpcs_caption_url', true );
		$session_youtube  = get_post_meta( $post->ID, '_wpcs_youtube_id', true );

		wp_nonce_field( 'edit-session-info', 'wpcs-meta-session-info' );
		?>

		<p>
			<label for="wpcs-session-date"><?php esc_html_e( 'Date:', 'wpa-conference' ); ?></label>
			<input type="text" id="wpcs-session-date" data-date="<?php echo esc_attr( $session_date ); ?>" name="wpcs-session-date" value="<?php echo esc_attr( $session_date ); ?>" /><br />
			<label><?php esc_html_e( 'Time:', 'wpa-conference' ); ?></label>

			<select name="wpcs-session-hour" aria-label="<?php esc_attr_e( 'Session Start Hour', 'wpa-conference' ); ?>">
					<option value="">Not assigned</option>
				<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $i, $session_hours ); ?>>
						<?php echo esc_html( $i ); ?>
					</option>
				<?php endfor; ?>
			</select> :

			<select name="wpcs-session-minutes" aria-label="<?php esc_attr_e( 'Session Start Minutes', 'wpa-conference' ); ?>">
				<?php for ( $i = '00'; (int) $i <= 55; $i = sprintf( '%02d', (int) $i + 5 ) ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $i, $session_minutes ); ?>>
						<?php echo esc_html( $i ); ?>
					</option>
				<?php endfor; ?>
			</select>

			<select name="wpcs-session-meridiem" aria-label="<?php esc_attr_e( 'Session Meridiem', 'wpa-conference' ); ?>">
				<option value="am" <?php selected( 'am', $session_meridiem ); ?>>am</option>
				<option value="pm" <?php selected( 'pm', $session_meridiem ); ?>>pm</option>
			</select>
		</p>
		<p>
			<label for="wpcs-session-type"><?php esc_html_e( 'Type:', 'wpa-conference' ); ?></label>
			<select id="wpcs-session-type" name="wpcs-session-type">
				<option value="session" <?php selected( $session_type, 'session' ); ?>><?php esc_html_e( 'Regular Session', 'wpa-conference' ); ?></option>
				<option value="panel" <?php selected( $session_type, 'panel' ); ?>><?php esc_html_e( 'Panel', 'wpa-conference' ); ?></option>
				<option value="lightning" <?php selected( $session_type, 'lightning' ); ?>><?php esc_html_e( 'Lightning Talks', 'wpa-conference' ); ?></option>
				<option value="custom" <?php selected( $session_type, 'custom' ); ?>><?php esc_html_e( 'Custom', 'wpa-conference' ); ?></option>
			</select>
		</p>
		<p>
			<label for="wpcs-session-youtube"><?php esc_html_e( 'YouTube ID', 'wpa-conference' ); ?></label>
			<input type="text" id="wpcs-session-youtube" name="wpcs-session-youtube" value="<?php echo esc_attr( $session_youtube ); ?>" />
		</p>
		<p>
			<label for="wpcs-session-caption"><?php esc_html_e( 'Caption URL:', 'wpa-conference' ); ?></label>
			<input type="text" id="wpcs-session-caption" name="wpcs-session-caption" value="<?php echo esc_attr( $session_captions ); ?>" />
		</p>

		<?php
	}

	/**
	 * Fired when a post is saved, updates additional sessions metadada.
	 *
	 * @param  int      $post_id The post ID.
	 * @param  \WP_POST $post    The post.
	 * @return void
	 */
	public function wpcs_save_post_session( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'wpcs_session' !== $post->post_type ) {
			return;
		}

		if ( isset( $_POST['wpcs-meta-speakers-list-nonce'] ) && wp_verify_nonce( sanitize_text_field( $_POST['wpcs-meta-speakers-list-nonce'] ), 'edit-speakers-list' ) && current_user_can( 'edit_post', $post_id ) ) {

			// Update the text box as is for backwards compatibility.
			$speakers = sanitize_text_field( $_POST['wpcs-speakers-list'] ?? '' );
			update_post_meta( $post_id, '_conference_session_speakers', $speakers );
		}

		if ( isset( $_POST['wpcs-meta-session-info'] ) && wp_verify_nonce( sanitize_text_field( $_POST['wpcs-meta-session-info'] ), 'edit-session-info' ) ) {

			// Update session time.
			if ( ! empty( $_POST['wpcs-session-hour'] ) ) {
				$session_time = strtotime(
					sprintf(
						'%s %d:%02d %s',
						sanitize_text_field( $_POST['wpcs-session-date'] ?? '' ),
						absint( $_POST['wpcs-session-hour'] ?? 0 ),
						absint( $_POST['wpcs-session-minutes'] ?? 0 ),
						isset( $_POST['wpcs-session-meridiem'] ) && 'am' === $_POST['wpcs-session-meridiem'] ? 'am' : 'pm'
					)
				);
			} else {
				$session_time = '';
			}
			update_post_meta( $post_id, '_wpcs_session_time', $session_time );
			update_user_meta( wp_get_current_user()->ID, '_last_entered', $session_time );

			// Update session type.
			$session_type = sanitize_text_field( $_POST['wpcs-session-type'] ?? '' );
			if ( ! in_array( $session_type, array( 'session', 'lightning', 'panel', 'custom' ), true ) ) {
				$session_type = 'session';
			}
			update_post_meta( $post_id, '_wpcs_session_type', $session_type );

			// Update session speakers.
			$session_speakers = sanitize_text_field( $_POST['wpcs-session-speakers'] ?? '' );
			update_post_meta( $post_id, '_wpcs_session_speakers', $session_speakers );

			// Update session YouTube ID.
			$session_youtube = sanitize_text_field( $_POST['wpcs-session-youtube'] ?? '' );
			update_post_meta( $post_id, '_wpcs_youtube_id', $session_youtube );

			// Update session caption URL.
			$session_caption = sanitize_text_field( $_POST['wpcs-session-caption'] ?? '' );
			update_post_meta( $post_id, '_wpcs_caption_url', $session_caption );

		}

	}

	/**
	 * Filters our custom post types columns.
	 *
	 * @uses current_filter()
	 * @see __construct()
	 *
	 * @param  array $columns The columns.
	 * @return array
	 */
	public function wpcs_manage_post_types_columns( $columns ) {
		$current_filter = current_filter();

		switch ( $current_filter ) {
			case 'manage_wpcs_session_posts_columns':
				$columns = array_slice( $columns, 0, 1, true ) + array( 'conference_session_time' => __( 'Time', 'wpa-conference' ) ) + array_slice( $columns, 1, null, true );
				break;
			default:
		}

		return $columns;
	}

	/**
	 * Custom columns output
	 *
	 * This generates the output to the extra columns added to the posts lists in the admin.
	 *
	 * @see wpcs_manage_post_types_columns()
	 *
	 * @param  string $column  The columns.
	 * @param  int    $post_id The post ID.
	 * @return void
	 */
	public function wpcs_manage_post_types_columns_output( $column, $post_id ) {
		switch ( $column ) {

			case 'conference_session_time':
				$session_time = absint( get_post_meta( $post_id, '_wpcs_session_time', true ) );
				$session_time = ( $session_time ) ? gmdate( 'H:i', $session_time ) : '&mdash;';
				echo esc_html( $session_time );
				break;

			default:
		}
	}

	/**
	 * Additional sortable columns for WP_Posts_List_Table.
	 *
	 * @param  array $sortable The sortable columns.
	 * @return array
	 */
	public function wpcs_manage_sortable_columns( $sortable ) {
		$current_filter = current_filter();

		if ( 'manage_edit-wpcs_session_sortable_columns' === $current_filter ) {
			$sortable['conference_session_time'] = '_wpcs_session_time';
		}

		return $sortable;
	}

	/**
	 * Display an additional post label if needed.
	 *
	 * @param  mixed $states The post states.
	 * @return mixed
	 */
	public function wpcs_display_post_states( $states ) {
		$post = get_post();
		if ( ! get_post_type( $post ) ) {
			return null;
		}

		if ( 'wpcs_session' !== $post->post_type ) {
			return $states;
		}

		$session_type = get_post_meta( $post->ID, '_wpcs_session_type', true );
		if ( ! in_array( $session_type, array( 'session', 'lightning', 'panel', 'custom' ), true ) ) {
			$session_type = 'session';
		}

		if ( 'session' === $session_type ) {
			$states['wpcs-session-type'] = __( 'Session', 'wpa-conference' );
		} elseif ( 'lightning' === $session_type ) {
			$states['wpcs-session-type'] = __( 'Lightning Talks', 'wpa-conference' );
		} elseif ( 'panel' === $session_type ) {
			$states['wpcs-session-type'] = __( 'Panel', 'wpa-conference' );
		}

		return $states;
	}

	/**
	 * The [wpcs_sponsors] shortcode handler.
	 *
	 * @param  array  $attr    The shortcode attributes.
	 * @param  string $content The shortcode content.
	 * @return string
	 */
	public function shortcode_sponsors( $attr, $content ) {
		global $post;

		$attr = shortcode_atts(
			array(
				'link'           => 'none', // 'website' or 'post'.
				'title'          => 'hidden',
				'content'        => 'hidden',
				'excerpt_length' => 55,
				'heading_level'  => 'h2',
				'level'          => 'platinum,gold,silver,bronze,microsponsor,donor',
				'exclude'        => '',
			),
			$attr
		);

		$levels  = ( '' !== $attr['level'] ) ? explode( ',', $attr['level'] ) : array();
		$exclude = ( '' !== $attr['exclude'] ) ? explode( ',', $attr['exclude'] ) : array();

		$attr['link'] = strtolower( $attr['link'] );
		$terms        = get_terms( 'wpcsp_sponsor_level', array( 'get' => 'all' ) );
		$sortable     = array();
		foreach ( $terms as $term ) {
			$sortable[ $term->slug ] = $term;
		}

		ob_start();
		?>

		<div class="wpcsp-sponsors">
			<?php
			if ( is_array( $levels ) && ! empty( $levels ) ) {
				$terms = array();
				foreach ( $levels as $level ) {
					$terms[] = ( isset( $sortable[ $level ] ) ) ? $sortable[ $level ] : array();
				}
			}
			foreach ( $terms as $term ) :
				if ( empty( $term ) ) {
					continue;
				}
				if ( '' !== $attr['level'] && ( ! in_array( $term->slug, $levels, true ) || in_array( $term->slug, $exclude, true ) ) ) {
					continue;
				}
				$sponsors = new WP_Query(
					array(
						'post_type'      => 'wpcsp_sponsor',
						'order'          => 'ASC',
						'orderby'        => 'title',
						'posts_per_page' => -1,
						'taxonomy'       => $term->taxonomy,
						'term'           => $term->slug,
					)
				);

				if ( ! $sponsors->have_posts() ) {
					continue;
				}
				?>

				<div class="wpcsp-sponsor-level wpcsp-sponsor-level-<?php echo sanitize_html_class( $term->slug ); ?>">
					<?php $heading_level = ( $attr['heading_level'] ) ? $attr['heading_level'] : 'h2'; ?>
					<<?php echo esc_html( $heading_level ); ?> class="wpcsp-sponsor-level-heading"><span><?php echo esc_html( $term->name ); ?></span></<?php echo esc_html( $heading_level ); ?>>

					<ul class="wpcsp-sponsor-list">
						<?php
						while ( $sponsors->have_posts() ) :
							$sponsors->the_post();
							$website     = get_post_meta( get_the_ID(), 'wpcsp_website_url', true );
							$logo_height = ( get_term_meta( $term->term_id, 'wpcsp_logo_height', true ) ) ? get_term_meta( $term->term_id, 'wpcsp_logo_height', true ) . 'px' : 'auto';
							$image       = ( has_post_thumbnail() ) ? '<img class="wpcsp-sponsor-image" src="' . get_the_post_thumbnail_url( get_the_ID(), 'full' ) . '" alt="' . get_the_title( get_the_ID() ) . '" style="width: auto; max-height: ' . $logo_height . ';"  />' : null;
							?>

							<li id="wpcsp-sponsor-<?php the_ID(); ?>" class="wpcsp-sponsor">
								<?php if ( 'visible' === $attr['title'] ) : ?>
									<?php if ( 'website' === $attr['link'] && $website ) : ?>
										<h3>
											<a href="<?php echo esc_url( $website ); ?>">
												<?php the_title(); ?>
											</a>
										</h3>
									<?php elseif ( 'post' === $attr['link'] ) : ?>
										<h3>
											<a href="<?php echo esc_url( get_permalink() ); ?>">
												<?php the_title(); ?>
											</a>
										</h3>
									<?php else : ?>
										<h3>
											<?php the_title(); ?>
										</h3>
									<?php endif; ?>
								<?php endif; ?>

								<div class="wpcsp-sponsor-description">
									<?php if ( 'website' === $attr['link'] && $website && $image ) : ?>
										<a href="<?php echo esc_url( $website ); ?>">
											<?php echo wp_kses_post( $image ); ?>
										</a>
									<?php elseif ( 'post' === $attr['link'] && $image ) : ?>
										<a href="<?php echo esc_url( get_permalink() ); ?>">
											<?php echo wp_kses_post( $image ); ?>
										</a>
									<?php else : ?>
										<?php echo wp_kses_post( $image ); ?>
									<?php endif; ?>

									<?php if ( 'full' === $attr['content'] ) : ?>
										<?php the_content(); ?>
									<?php elseif ( 'excerpt' === $attr['content'] ) : ?>
										<?php
										echo wp_kses_post(
											wpautop(
												wp_trim_words(
													get_the_content(),
													absint( $attr['excerpt_length'] ),
													apply_filters( 'excerpt_more', ' ' . '&hellip;' )
												)
											)
										);
										?>
									<?php endif; ?>
								</div>
							</li>
						<?php endwhile; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>

		<?php

		wp_reset_postdata();
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	/**
	 * The [wpcs_speakers] shortcode handler.
	 *
	 * @param  array $attr The shortcode attributes.
	 * @return string
	 */
	public function shortcode_speakers( $attr ) {
		global $post;

		// Prepare the shortcodes arguments.
		$attr = shortcode_atts(
			array(
				'show_image'     => true,
				'image_size'     => 150,
				'show_content'   => true,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'desc',
				'speaker_link'   => '',
				'track'          => '',
				'groups'         => '',
				'columns'        => 1,
				'gap'            => 30,
				'align'          => 'left',
				'heading_level'  => 'h2',
			),
			$attr
		);

		foreach ( array( 'orderby', 'order', 'speaker_link' ) as $key_for_case_sensitive_value ) {
			$attr[ $key_for_case_sensitive_value ] = strtolower( $attr[ $key_for_case_sensitive_value ] );
		}

		$attr['show_image']   = $this->str_to_bool( $attr['show_image'] );
		$attr['show_content'] = $this->str_to_bool( $attr['show_content'] );
		$attr['orderby']      = in_array( $attr['orderby'], array( 'date', 'title', 'rand' ), true ) ? $attr['orderby'] : 'date';
		$attr['order']        = in_array( $attr['order'], array( 'asc', 'desc' ), true ) ? $attr['order'] : 'desc';
		$attr['speaker_link'] = in_array( $attr['speaker_link'], array( 'permalink' ), true ) ? $attr['speaker_link'] : '';
		$attr['track']        = array_filter( explode( ',', $attr['track'] ) );
		$attr['groups']       = array_filter( explode( ',', $attr['groups'] ) );

		// Fetch all the relevant sessions.
		$session_args = array(
			'post_type'      => 'wpcs_session',
			'posts_per_page' => -1,
		);

		if ( ! empty( $attr['track'] ) ) {
			$session_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wpcs_track',
					'field'    => 'slug',
					'terms'    => $attr['track'],
				),
			);
		}

		$sessions = get_posts( $session_args );

		// Parse the sessions.
		$speaker_ids     = array();
		$speakers_tracks = array();
		foreach ( $sessions as $session ) {
			// Get the speaker IDs for all the sessions in the requested tracks.
			$session_speaker_ids = get_post_meta( $session->ID, '_rwc_cs_speaker_id' );
			$speaker_ids         = array_merge( $speaker_ids, $session_speaker_ids );

			// Map speaker IDs to their corresponding tracks.
			$session_terms = wp_get_object_terms( $session->ID, 'RWC_track' );
			foreach ( $session_speaker_ids as $speaker_id ) {
				if ( isset( $speakers_tracks[ $speaker_id ] ) ) {
					$speakers_tracks[ $speaker_id ] = array_merge( $speakers_tracks[ $speaker_id ], wp_list_pluck( $session_terms, 'slug' ) );
				} else {
					$speakers_tracks[ $speaker_id ] = wp_list_pluck( $session_terms, 'slug' );
				}
			}
		}

		// Remove duplicate entries.
		$speaker_ids = array_unique( $speaker_ids );
		foreach ( $speakers_tracks as $speaker_id => $tracks ) {
			$speakers_tracks[ $speaker_id ] = array_unique( $tracks );
		}

		// Fetch all specified speakers.
		$speaker_args = array(
			'post_type'      => 'wpcsp_speaker',
			'posts_per_page' => intval( $attr['posts_per_page'] ),
			'orderby'        => $attr['orderby'],
			'order'          => $attr['order'],
		);

		if ( ! empty( $attr['track'] ) ) {
			$speaker_args['post__in'] = $speaker_ids;
		}

		if ( ! empty( $attr['groups'] ) ) {
			$speaker_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wpcsp_speaker_level',
					'field'    => 'slug',
					'terms'    => $attr['groups'],
				),
			);
		}

		$speakers = new WP_Query( $speaker_args );

		if ( ! $speakers->have_posts() ) {
			return '';
		}
		$heading_level = ( in_array( $attr['heading_level'], array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ) ? $attr['heading_level'] : 'h2';
		// Render the HTML for the shortcode.
		ob_start();
		?>

		<div class="wpcsp-speakers" style="text-align: <?php echo esc_attr( $attr['align'] ); ?>; display: grid; grid-template-columns: repeat(<?php echo esc_attr( $attr['columns'] ); ?>, 1fr); grid-gap: <?php echo esc_attr( $attr['gap'] ); ?>px;">

			<?php
			while ( $speakers->have_posts() ) :
				$speakers->the_post();

				$post_id            = get_the_ID();
				$first_name         = get_post_meta( $post_id, 'wpcsp_first_name', true );
				$last_name          = get_post_meta( $post_id, 'wpcsp_last_name', true );
				$full_name          = $first_name . ' ' . $last_name;
				$title_organization = array();
				$title              = ( get_post_meta( $post_id, 'wpcsp_title', true ) ) ? $title_organization[] = get_post_meta( $post_id, 'wpcsp_title', true ) : null;
				$organization       = ( get_post_meta( $post_id, 'wpcsp_organization', true ) ) ? $title_organization[] = get_post_meta( $post_id, 'wpcsp_organization', true ) : null;

				$speaker_classes = array( 'wpcsp-speaker', 'wpcsp-speaker-' . sanitize_html_class( $post->post_name ) );

				if ( isset( $speakers_tracks[ get_the_ID() ] ) ) {
					foreach ( $speakers_tracks[ get_the_ID() ] as $track ) {
						$speaker_classes[] = sanitize_html_class( 'wpcsp-track-' . $track );
					}
				}
				?>

				<!-- Organizers note: The id attribute is deprecated and only remains for backwards compatibility, please use the corresponding class to target individual speakers -->
				<div class="wpcsp-speaker" id="wpcsp-speaker-<?php echo sanitize_html_class( $post->post_name ); ?>" class="<?php echo esc_attr( implode( ' ', $speaker_classes ) ); ?>">

					<?php
					if ( has_post_thumbnail( $post_id ) && true === $attr['show_image'] ) {
						echo get_the_post_thumbnail( $post_id, array( $attr['image_size'], $attr['image_size'] ), array( 'class' => 'wpcsp-speaker-image' ) );}
					?>

					<<?php echo esc_html( $heading_level ); ?> class="wpcsp-speaker-name">
						<?php if ( 'permalink' === $attr['speaker_link'] ) : ?>

							<a href="<?php the_permalink(); ?>">
								<?php echo wp_kses_post( $full_name ); ?>
							</a>

						<?php else : ?>

							<?php echo wp_kses_post( $full_name ); ?>

						<?php endif; ?>
					</<?php echo esc_html( $heading_level ); ?>>

					<?php if ( $title_organization ) { ?>
						<p class="wpcsp-speaker-title-organization">
							<?php echo wp_kses_post( implode( ', ', $title_organization ) ); ?>
						</p>
					<?php } ?>

					<div class="wpcsp-speaker-description">
						<?php
						if ( true === $attr['show_content'] ) {
							the_content();}
						?>
					</div>
				</div>

			<?php endwhile; ?>

		</div>

		<?php

		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Convert a string representation of a boolean to an actual boolean
	 *
	 * @param string|bool $value The value to convert.
	 *
	 * @return bool
	 */
	public function str_to_bool( $value ) {
		if ( true === $value ) {
			return true;
		}

		if ( in_array( strtolower( (string) trim( $value ) ), array( 'yes', 'true', '1' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter session speaker meta field
	 *
	 * @param \CMB2_Base $cmb The CMB2 object.
	 * @return \CMB2_Base
	 */
	public function filter_session_speaker_meta_field( $cmb ) {

		$cmb->add_field(
			array(
				'name'             => 'Speaker Display',
				'id'               => 'wpcsp_session_speaker_display',
				'type'             => 'radio',
				'show_option_none' => false,
				'options'          => array(
					'typed' => __( 'Speaker Names (Typed)', 'wpa-conference' ),
					'cpt'   => __( 'Speaker Select (from Speakers CPT)', 'wpa-conference' ),
				),
				'default'          => 'cpt',
			)
		);

		// Get speakers.
		$args     = array(
			'numberposts' => -1,
			'post_type'   => 'wpcsp_speaker',
			'post_status' => array( 'pending', 'publish' ),
		);
		$speakers = get_posts( $args );
		$speakers = wp_list_pluck( $speakers, 'post_title', 'ID' );

		$cmb->add_field(
			array(
				'name'       => 'Speakers',
				'id'         => 'wpcsp_session_speakers',
				'desc'       => 'Select speakers. Drag to reorder.',
				'type'       => 'pw_multiselect',
				'options'    => $speakers,
				'attributes' => array(
					'data-conditional-id'    => 'wpcsp_session_speaker_display',
					'data-conditional-value' => 'cpt',
				),
			)
		);

		// Speaker Name(s).
		$cmb->add_field(
			array(
				'name'       => __( 'Speaker Name(s)', 'wpa-conference' ),
				'id'         => '_wpcs_session_speakers',
				'type'       => 'text',
				'attributes' => array(
					'data-conditional-id'    => 'wpcsp_session_speaker_display',
					'data-conditional-value' => 'typed',
				),
			)
		);

		// Get sponsors.
		$args    = array(
			'numberposts' => -1,
			'post_type'   => 'wpcsp_sponsor',
		);
		$sponsor = get_posts( $args );
		$sponsor = wp_list_pluck( $sponsor, 'post_title', 'ID' );

		$cmb->add_field(
			array(
				'name'    => 'Sponsors',
				'id'      => 'wpcsp_session_sponsors',
				'desc'    => 'Select sponsor. Drag to reorder.',
				'type'    => 'pw_multiselect',
				'options' => $sponsor,
			)
		);

		$cmb->add_field(
			array(
				'name'       => 'Slides',
				'id'         => 'wpcsp_session_slides',
				'type'       => 'text',
				'repeatable' => true,
			)
		);

		$cmb->add_field(
			array(
				'name'       => 'Resources',
				'id'         => 'wpcsp_session_resources',
				'type'       => 'text',
				'repeatable' => true,
			)
		);

		return $cmb;
	}

	/**
	 * Get the session speakers HTML.
	 *
	 * @param  string $speakers_typed The manually created speaker HTML.
	 * @param  int    $session_id     The session ID.
	 * @return string
	 */
	public function filter_session_speakers( $speakers_typed, $session_id ) {

		$speaker_display = get_post_meta( $session_id, 'wpcsp_session_speaker_display', true );

		if ( 'typed' === $speaker_display ) {
			return $speakers_typed;
		}

		$html         = '';
		$speakers_cpt = get_post_meta( $session_id, 'wpcsp_session_speakers', true );

		if ( $speakers_cpt ) {
			ob_start();
			foreach ( $speakers_cpt as $post_id ) {
				$first_name         = get_post_meta( $post_id, 'wpcsp_first_name', true );
				$last_name          = get_post_meta( $post_id, 'wpcsp_last_name', true );
				$full_name          = $first_name . ' ' . $last_name;
				$title_organization = array();

				?>
				<div class="wpcsp-session-speaker">

					<?php if ( $full_name ) { ?>
						<div class="wpcsp-session-speaker-name">
							<?php echo wp_kses_post( $full_name ); ?>
						</div>
					<?php } ?>

					<?php if ( $title_organization ) { ?>
						<div class="wpcsp-session-speaker-title-organization">
							<?php echo wp_kses_post( implode( ', ', $title_organization ) ); ?>
						</div>
					<?php } ?>

				</div>
				<?php
			}
			$html .= ob_get_clean();
		}

		return $html;
	}

	/**
	 * Get single session speaker HTML.
	 *
	 * @param  string $speakers_typed The manually typed speaker HTML.
	 * @param  int    $session_id     The session ID.
	 * @return string
	 */
	public function filter_single_session_speakers( $speakers_typed, $session_id ) {

		$speaker_display = get_post_meta( $session_id, 'wpcsp_session_speaker_display', true );
		if ( 'typed' === $speaker_display ) {
			return $speakers_typed;
		}

		$html         = '';
		$speakers_cpt = get_post_meta( $session_id, 'wpcsp_session_speakers', true );

		if ( $speakers_cpt ) {
			ob_start();
			?>
			<div class="wpcsp-single-session-speakers">
				<h2 class="wpcsp-single-session-speakers-title">Speakers</h2>
				<?php
				foreach ( $speakers_cpt as $post_id ) {
					$first_name         = get_post_meta( $post_id, 'wpcsp_first_name', true );
					$last_name          = get_post_meta( $post_id, 'wpcsp_last_name', true );
					$full_name          = $first_name . ' ' . $last_name;
					$title_organization = array();

					?>
					<div class="wpcsp-single-session-speakers-speaker">

						<?php
						if ( has_post_thumbnail( $post_id ) ) {
							echo get_the_post_thumbnail( $post_id, 'thumbnail', array( 'class' => 'wpcsp-single-session-speakers-speaker-image' ) );
						}
						?>

						<?php if ( $full_name ) { ?>
							<h3 class="wpcsp-single-session-speakers-speaker-name">
								<a href="<?php the_permalink( $post_id ); ?>">
									<?php echo wp_kses_post( $full_name ); ?>
								</a>
							</h3>
						<?php } ?>

						<?php if ( $title_organization ) { ?>
							<div class="wpcsp-single-session-speakers-speaker-title-organization">
								<?php echo wp_kses_post( implode( ', ', $title_organization ) ); ?>
							</div>
						<?php } ?>

					</div>
					<?php
				}
				?>
			</div>
			<?php
			$html .= ob_get_clean();
		}

		return $html;
	}

	/**
	 * Get the session content header.
	 *
	 * @param  int $session_id The session ID.
	 * @return string
	 */
	public function session_content_header( $session_id ) {
		$html         = '';
		$session_tags = get_the_terms( $session_id, 'wpcs_session_tag' );
		if ( $session_tags ) {
			ob_start();
			?>
			<ul class="wpcsp-session-tags">
				<?php foreach ( $session_tags as $session_tag ) { ?>
					<?php
					$term_url = get_term_link( $session_tag->term_id, 'wpcs_session_tag' );

					if ( is_wp_error( $term_url ) ) {
						$term_url = '';
					}
					?>
					<li class="wpcsp-session-tags-tag">
						<a href="<?php echo esc_url( $term_url ); ?>" class="wpcsp-session-tags-tag-link">
							<?php echo wp_kses_post( $session_tag->name ); ?>
						</a>
					</li>
				<?php } ?>
			</ul>
			<?php
			$html = ob_get_clean();
		}
		return $html;
	}

	/**
	 * Output single session tags.
	 *
	 * @return void
	 */
	public function single_session_tags() {
		$terms = get_the_terms( get_the_ID(), 'wpcs_session_tag' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$term_names = wp_list_pluck( $terms, 'name' );
			$terms      = implode( ', ', $term_names );
			if ( $terms ) {
				echo '<li class="wpsc-single-session-taxonomies-taxonomy wpsc-single-session-location"><i class="fas fa-tag"></i>' . wp_kses_post( $terms ) . '</li>';
			}
		}
	}

	/**
	 * Output Session Sponsors.
	 *
	 * @param int $session_id The session ID.
	 * @return mixed
	 */
	public function session_sponsors( $session_id ) {

		$session_sponsors = get_post_meta( $session_id, 'wpcsp_session_sponsors', true );
		if ( ! $session_sponsors ) {
			return '';
		}

		$sponsors = array();
		foreach ( $session_sponsors as $sponser_li ) {
			$sponsors[] .= get_the_title( $sponser_li );
		}

		ob_start();

		if ( $sponsors ) {
			echo '<div class="wpcs-session-sponsor"><span class="wpcs-session-sponsor-label">Presented by: </span>' . wp_kses_post( implode( ', ', $sponsors ) ) . '</div>';
		}

		$html = ob_get_clean();
		return $html;

	}

}

/**
 * Plugin Activation & Deactivation
 */
register_activation_hook( __FILE__, 'wpcsp_pro_activation' );
register_deactivation_hook( __FILE__, 'wpcsp_pro_deactivation' );
register_uninstall_hook( __FILE__, 'wpcsp_pro_uninstall' );

/**
 * Define file path and basename
 */
$ac_pro_plugin_directory = __FILE__;
$ac_pro_plugin_basename  = plugin_basename( __FILE__ );

/**
 * Filters and Actions
 */
add_action( 'wp_enqueue_scripts', 'wpcsp_pro_enqueue_styles' );
add_action( 'cmb2_admin_init', 'wpcsp_speaker_metabox' );
add_action( 'cmb2_admin_init', 'wpcsp_sponsor_metabox' );
add_action( 'cmb2_admin_init', 'wpcsp_sponsor_level_metabox' );

/**
 * Generate speaker metaboxes.
 *
 * @return void
 */
function wpcsp_speaker_metabox() {

	$cmb = new_cmb2_box(
		array(
			'id'           => 'wpcsp_speaker_metabox',
			'title'        => __( 'Speaker Information', 'wpa-conference' ),
			'object_types' => array( 'wpcsp_speaker' ), // Post type.
			'context'      => 'normal',
			'priority'     => 'high',
			'show_names'   => true, // Show field names on the left.
		)
	);

	// First name.
	$cmb->add_field(
		array(
			'name' => __( 'First Name', 'wpa-conference' ),
			'id'   => 'wpcsp_first_name',
			'type' => 'text',
		)
	);

	// Last name.
	$cmb->add_field(
		array(
			'name' => __( 'Last Name', 'wpa-conference' ),
			'id'   => 'wpcsp_last_name',
			'type' => 'text',
		)
	);

	// Title.
	$cmb->add_field(
		array(
			'name' => __( 'Title', 'wpa-conference' ),
			'id'   => 'wpcsp_title',
			'type' => 'text',
		)
	);

	// Organization.
	$cmb->add_field(
		array(
			'name' => __( 'Organization', 'wpa-conference' ),
			'id'   => 'wpcsp_organization',
			'type' => 'text',
		)
	);

	// Facebook URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Facebook URL', 'wpa-conference' ),
			'id'        => 'wpcsp_facebook_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Twitter URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Twitter URL', 'wpa-conference' ),
			'id'        => 'wpcsp_twitter_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Github URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Github URL', 'wpa-conference' ),
			'id'        => 'wpcsp_github_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// WordPress Profile URL.
	$cmb->add_field(
		array(
			'name'      => __( 'WordPress Profile URL', 'wpa-conference' ),
			'id'        => 'wpcsp_wordpress_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Instagram URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Instagram URL', 'wpa-conference' ),
			'id'        => 'wpcsp_instagram_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Linkedin URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Linkedin URL', 'wpa-conference' ),
			'id'        => 'wpcsp_linkedin_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// YouTube URL.
	$cmb->add_field(
		array(
			'name'      => __( 'YouTube URL', 'wpa-conference' ),
			'id'        => 'wpcsp_youtube_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Website URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Website URL', 'wpa-conference' ),
			'id'        => 'wpcsp_website_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);
}

/**
 * Generate sponsor metaboxes.
 *
 * @return void
 */
function wpcsp_sponsor_metabox() {

	$cmb = new_cmb2_box(
		array(
			'id'           => 'wpcsp_sponsor_metabox',
			'title'        => __( 'Sponsor Information', 'wpa-conference' ),
			'object_types' => array( 'wpcsp_sponsor' ), // Post type.
			'context'      => 'normal',
			'priority'     => 'high',
			'show_names'   => true, // Show field names on the left.
		)
	);

	// Website URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Website URL', 'wpa-conference' ),
			'id'        => 'wpcsp_website_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Instagram URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Instagram URL', 'wpa-conference' ),
			'id'        => 'wpcsp_instagram_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Linkedin URL.
	$cmb->add_field(
		array(
			'name'      => __( 'Linkedin URL', 'wpa-conference' ),
			'id'        => 'wpcsp_linkedin_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// YouTube URL.
	$cmb->add_field(
		array(
			'name'      => __( 'YouTube URL', 'wpa-conference' ),
			'id'        => 'wpcsp_youtube_url',
			'type'      => 'text_url',
			'protocols' => array( 'http', 'https' ), // Array of allowed protocols.
		)
	);

	// Sponsor Swag.
	$cmb->add_field(
		array(
			'name' => __( 'Digital Swag', 'wpa-conference' ),
			'desc' => __( 'Use this field to add swag for attendees.', 'wpa-conference' ),
			'id'   => 'wpcsp_sponsor_swag',
			'type' => 'wysiwyg',
		)
	);
}

/**
 * Generate the sponsor level metabox.
 *
 * @return void
 */
function wpcsp_sponsor_level_metabox() {

	$cmb = new_cmb2_box(
		array(
			'id'           => 'wpcsp_sponsor_level_metabox',
			'title'        => esc_html__( 'Category Metabox', 'wpa-conference' ), // Doesn't output for term boxes.
			'object_types' => array( 'term' ), // Tells CMB2 to use term_meta vs post_meta.
			'taxonomies'   => array( 'wpcsp_sponsor_level' ), // Tells CMB2 which taxonomies should have these fields.
		)
	);

	// Logo Height.
	$cmb->add_field(
		array(
			'name'       => __( 'Logo Height', 'wpa-conference' ),
			'desc'       => __( 'Pixels', 'wpa-conference' ),
			'id'         => 'wpcsp_logo_height',
			'type'       => 'text_small',
			'attributes' => array(
				'type'    => 'number',
				'pattern' => '\d*',
			),
		)
	);

}

// Load the plugin class.
$GLOBALS['wpcs_plugin'] = new WPCS_Conference_Schedule();

/**
 * Get video HTML.
 *
 * @return string
 */
function wpcs_get_video() {
	return '
	<div class="wp-block-group alignwide wpad-video-player">
		<h2>Session Video</h2>
		<video id="able-player-' . get_the_ID() . '" data-skin="2020" data-able-player data-transcript-div="able-player-transcript-' . get_the_ID() . '" preload="auto" poster="' . wpcs_get_poster() . '" data-youtube-id="' . wpcs_get_youtube() . '">
			<track kind="captions" src="' . wpcs_get_captions() . '" srclang="en" label="English">
		</video>
		<div id="able-player-transcript-' . get_the_ID() . '"></div>
	</div>';
}

/**
 * Get video poster (the post thumbnail URL).
 *
 * @return string
 */
function wpcs_get_poster() {
	$poster = get_the_post_thumbnail_url();

	return $poster;
}

/**
 * Get captions URL.
 *
 * @return string
 */
function wpcs_get_captions() {
	$post_id          = get_the_ID();
	$session_captions = get_post_meta( $post_id, '_wpcs_caption_url', true );

	return $session_captions;
}

/**
 * Get youtube ID from meta.
 *
 * @return string
 */
function wpcs_get_youtube() {
	$post_id         = get_the_ID();
	$session_youtube = get_post_meta( $post_id, '_wpcs_youtube_id', true );

	return $session_youtube;
}
