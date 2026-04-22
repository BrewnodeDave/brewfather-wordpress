<?php
/**
 * Plugin Name: Brewfather Sync (With Full Recipe)
 * Description: Automatically creates a blog post with full recipe details using the Brewfather API V2.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Admin settings page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'bf_add_settings_page' );
add_action( 'admin_init', 'bf_register_settings' );

function bf_add_settings_page() {
    add_options_page(
        'Brewfather Sync',
        'Brewfather Sync',
        'manage_options',
        'brewfather-sync',
        'bf_settings_page_html'
    );
}

function bf_register_settings() {
    register_setting( 'bf_settings_group', 'bf_user_id', array(
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    register_setting( 'bf_settings_group', 'bf_api_key', array(
        'sanitize_callback' => 'sanitize_text_field',
    ) );
}

function bf_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $msg = sanitize_text_field( $_GET['bf_msg'] ?? '' );
    if ( $msg === 'ok' ) {
        echo '<div class="notice notice-success is-dismissible"><p>Fermentation readings updated.</p></div>';
    } elseif ( $msg === 'api_error' ) {
        echo '<div class="notice notice-error"><p>Could not fetch readings from Brewfather API. Check your credentials.</p></div>';
    } elseif ( $msg === 'invalid' ) {
        echo '<div class="notice notice-error"><p>Invalid post or missing Brewfather batch ID.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Brewfather Sync Settings', 'brewfather-sync' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'bf_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="bf_user_id">Brewfather User ID</label></th>
                    <td><input type="text" id="bf_user_id" name="bf_user_id" value="<?php echo esc_attr( get_option( 'bf_user_id' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bf_api_key">Brewfather API Key</label></th>
                    <td><input type="password" id="bf_api_key" name="bf_api_key" value="<?php echo esc_attr( get_option( 'bf_api_key' ) ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2>Refresh Fermentation Readings</h2>
        <p>If a post was created before API credentials were saved, its fermentation graph will be empty. Enter the WordPress post ID below to re-fetch readings from Brewfather.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="bf_refresh_readings">
            <?php wp_nonce_field( 'bf_refresh_readings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="bf_refresh_post_id">WordPress Post ID</label></th>
                    <td><input type="number" id="bf_refresh_post_id" name="post_id" value="" class="small-text" min="1" required /></td>
                </tr>
            </table>
            <?php submit_button( 'Refresh Readings', 'secondary' ); ?>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Brewfather API V2 — fetch a single batch (complete=true for all fields)
// ---------------------------------------------------------------------------

function bf_fetch_batch( string $batch_id ) {
    $user_id = get_option( 'bf_user_id' );
    $api_key = get_option( 'bf_api_key' );

    if ( empty( $user_id ) || empty( $api_key ) ) {
        return new WP_Error( 'no_credentials', 'Brewfather API credentials not configured.', array( 'status' => 500 ) );
    }

    $url      = 'https://api.brewfather.app/v2/batches/' . rawurlencode( $batch_id ) . '?complete=true';
    $auth     = base64_encode( $user_id . ':' . $api_key );
    $response = wp_remote_get( $url, array(
        'headers' => array( 'Authorization' => 'Basic ' . $auth ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'api_error', 'Brewfather API returned HTTP ' . $code, array( 'status' => 502 ) );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'parse_error', 'Could not parse Brewfather API response.', array( 'status' => 502 ) );
    }

    return $data;
}

// ---------------------------------------------------------------------------
// REST endpoint — accepts {"_id":"<batch_id>"} and fetches from API V2
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {
    register_rest_route( 'bf-sync/v1', '/post-batch', array(
        'methods'             => 'POST',
        'callback'            => 'bf_handle_batch_request',
        'permission_callback' => '__return_true',
    ) );
} );

// ---------------------------------------------------------------------------
// Helper — convert EBC colour value to an approximate hex colour
// ---------------------------------------------------------------------------

function bf_ebc_to_hex( float $ebc ): string {
    $srm_colors = [
        1  => '#FFE699', 2  => '#FFD878', 3  => '#FFCA5A', 4  => '#FFBF42',
        5  => '#FBB123', 6  => '#F8A600', 7  => '#F39C00', 8  => '#EA8F00',
        9  => '#E58500', 10 => '#DE7C00', 11 => '#D77200', 12 => '#CF6900',
        13 => '#CB6100', 14 => '#C35900', 15 => '#BB5100', 16 => '#B54C00',
        17 => '#B04500', 18 => '#A63E00', 19 => '#A13700', 20 => '#9B3200',
        21 => '#952D00', 22 => '#8E2900', 23 => '#882300', 24 => '#821E00',
        25 => '#7B1A00', 26 => '#771900', 27 => '#701400', 28 => '#6A0E00',
        29 => '#660D00', 30 => '#600903', 31 => '#5E0607', 32 => '#59020A',
        33 => '#55040B', 34 => '#4F020C', 35 => '#4A020D', 36 => '#46010E',
        37 => '#43010E', 38 => '#3F010F', 39 => '#3B010F', 40 => '#38010F',
    ];
    $srm = (int) max( 1, min( 40, round( $ebc / 1.97 ) ) );
    return $srm_colors[ $srm ] ?? '#38010F';
}

function bf_handle_batch_request( WP_REST_Request $request ) {
    // get_json_params() only works when Content-Type: application/json is set.
    // Fall back to manually decoding the raw body so Brewfather's Custom Endpoint
    // works regardless of the Content-Type header it sends.
    $body = $request->get_json_params();
    if ( empty( $body ) ) {
        $body = json_decode( $request->get_body(), true );
    }

    if ( empty( $body ) || ! is_array( $body ) ) {
        error_log( 'BF Sync: empty or non-JSON body. Raw: ' . substr( $request->get_body(), 0, 500 ) );
        return new WP_Error( 'no_data', 'Could not parse request body', array( 'status' => 400 ) );
    }

    if ( ! isset( $body['_id'] ) ) {
        error_log( 'BF Sync: parsed body missing _id. Keys: ' . implode( ', ', array_keys( $body ) ) );
        return new WP_Error( 'no_id', 'Missing batch _id', array( 'status' => 400 ) );
    }

    $bf_id = sanitize_text_field( $body['_id'] );

    // Prevent duplicates
    $existing = get_posts( array(
        'meta_key'       => '_bf_batch_id',
        'meta_value'     => $bf_id,
        'post_type'      => 'post',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ) );
    if ( ! empty( $existing ) ) {
        return array( 'success' => false, 'message' => 'Already posted.' );
    }

    // Use the payload Brewfather already sent if it includes recipe data,
    // otherwise fetch from the Brewfather API V2 (e.g. manual/curl trigger).
    if ( isset( $body['recipe'] ) && is_array( $body['recipe'] ) ) {
        $data = $body;
    } else {
        $data = bf_fetch_batch( $bf_id );
        if ( is_wp_error( $data ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => $data->get_error_message() ),
                $data->get_error_data()['status'] ?? 502
            );
        }
    }

    // Basic info
    $name   = sanitize_text_field( $data['recipe']['name'] ?? 'Unknown Recipe' );
    $number = sanitize_text_field( $data['batchNo'] ?? '?' );
    $style  = sanitize_text_field( $data['recipe']['style']['name'] ?? '' );

    // Colour swatch
    $ebc    = $data['estimatedColor'] ?? null;
    $hex    = $ebc !== null ? bf_ebc_to_hex( (float) $ebc ) : null;
    $swatch = $hex ? ' <span style="display:inline-block;width:14px;height:14px;background:' . esc_attr( $hex ) . ';border:1px solid #888;vertical-align:middle;border-radius:3px;"></span>' : '';

    // --- Content generation ---
    $content = '<p>Details for <strong>' . esc_html( $name ) . '</strong>.</p>';

    if ( $style ) {
        $ebc_label = $ebc !== null ? ' (' . esc_html( (int) round( $ebc ) ) . ' EBC)' . $swatch : '';
        $content  .= '<p><strong>Style:</strong> ' . esc_html( $style ) . $ebc_label . '</p>';
    }

    // 1. Brew stats table
    $og_target   = $data['estimatedOg']  ?? null;
    $og_measured = $data['measuredOg']   ?? null;
    $fg_target   = $data['estimatedFg']  ?? null;
    $fg_measured = $data['measuredFg']   ?? null;
    $ibu         = $data['estimatedIbu'] ?? null;
    $abv_est     = $data['estimatedAbv'] ?? null;
    $abv_meas    = $data['measuredAbv']  ?? null;
    $batch_size  = $data['batchSize']    ?? ( $data['recipe']['batchSize'] ?? null );
    $efficiency  = $data['efficiency']   ?? ( $data['recipe']['efficiency'] ?? null );

    $rows = array();
    if ( $og_target !== null || $og_measured !== null )
        $rows[] = '<tr><td><strong>OG</strong></td><td>' . esc_html( $og_target ?? '–' ) . '</td><td>' . esc_html( $og_measured ?? '–' ) . '</td></tr>';
    if ( $fg_target !== null || $fg_measured !== null )
        $rows[] = '<tr><td><strong>FG</strong></td><td>' . esc_html( $fg_target ?? '–' ) . '</td><td>' . esc_html( $fg_measured ?? '–' ) . '</td></tr>';
    if ( $abv_est !== null || $abv_meas !== null )
        $rows[] = '<tr><td><strong>ABV</strong></td><td>' . esc_html( $abv_est  !== null ? $abv_est  . '%' : '–' ) . '</td><td>' . esc_html( $abv_meas !== null ? $abv_meas . '%' : '–' ) . '</td></tr>';
    if ( $ibu !== null )
        $rows[] = '<tr><td><strong>IBU</strong></td><td>' . esc_html( (int) round( $ibu ) ) . '</td><td>–</td></tr>';
    if ( $ebc !== null )
        $rows[] = '<tr><td><strong>Colour</strong></td><td>' . esc_html( (int) round( $ebc ) ) . ' EBC' . $swatch . '</td><td>–</td></tr>';
    if ( $batch_size !== null )
        $rows[] = '<tr><td><strong>Batch size</strong></td><td>' . esc_html( $batch_size ) . ' L</td><td>–</td></tr>';
    if ( $efficiency !== null )
        $rows[] = '<tr><td><strong>Efficiency</strong></td><td>' . esc_html( $efficiency ) . '%</td><td>–</td></tr>';

    if ( ! empty( $rows ) ) {
        $content .= '<h3>Brew Stats</h3>';
        $content .= '<table><thead><tr><th>Stat</th><th>Target</th><th>Measured</th></tr></thead><tbody>';
        $content .= implode( '', $rows );
        $content .= '</tbody></table>';
    }

    // 2. Brew timeline
    $timeline = array();
    foreach ( array(
        'Brew date'          => $data['brewDate']              ?? null,
        'Fermentation start' => $data['fermentationStartDate'] ?? null,
        'Bottling / kegging' => $data['bottlingDate']          ?? null,
    ) as $label => $ts ) {
        if ( $ts ) {
            $timeline[] = '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( date_i18n( 'd M Y', (int) ( $ts / 1000 ) ) ) . '</li>';
        }
    }
    if ( ! empty( $timeline ) ) {
        $content .= '<h3>Timeline</h3><ul>' . implode( '', $timeline ) . '</ul>';
    }

    // 3. Fermentables
    if ( ! empty( $data['recipe']['fermentables'] ) ) {
        $content .= '<h3>Grain Bill</h3><ul>';
        foreach ( $data['recipe']['fermentables'] as $f ) {
            $type      = isset( $f['type'] ) ? ' <em>(' . esc_html( $f['type'] ) . ')</em>' : '';
            $grain_ebc = isset( $f['ebc'] )  ? ', ' . esc_html( $f['ebc'] ) . ' EBC' : '';
            $content  .= '<li>' . esc_html( $f['amount'] ) . ' kg – ' . esc_html( $f['name'] ) . $type . $grain_ebc . '</li>';
        }
        $content .= '</ul>';
    }

    // 4. Hops
    if ( ! empty( $data['recipe']['hops'] ) ) {
        $content .= '<h3>Hops</h3><ul>';
        foreach ( $data['recipe']['hops'] as $h ) {
            $grams   = round( $h['amount'] );
            $alpha   = isset( $h['alpha'] ) ? ', ' . esc_html( $h['alpha'] ) . '% AA' : '';
            $content .= '<li>' . esc_html( $grams ) . 'g – ' . esc_html( $h['name'] ) . $alpha . ' (' . esc_html( $h['use'] ) . ' @ ' . esc_html( $h['time'] ) . ' min)</li>';
        }
        $content .= '</ul>';
    }

    // 5. Yeast
    if ( ! empty( $data['recipe']['yeasts'] ) ) {
        $content .= '<h3>Yeast</h3><ul>';
        foreach ( $data['recipe']['yeasts'] as $y ) {
            $details = array();
            if ( isset( $y['attenuation'] ) )              $details[] = 'Attenuation: ' . esc_html( $y['attenuation'] ) . '%';
            if ( isset( $y['minTemp'], $y['maxTemp'] ) )   $details[] = 'Temp: ' . esc_html( $y['minTemp'] ) . '–' . esc_html( $y['maxTemp'] ) . '°C';
            if ( isset( $y['flocculation'] ) )             $details[] = 'Flocculation: ' . esc_html( $y['flocculation'] );
            $detail_str = $details ? ' <small>(' . implode( ', ', $details ) . ')</small>' : '';
            $content   .= '<li>' . esc_html( $y['name'] ) . ' (' . esc_html( $y['laboratory'] ) . ')' . $detail_str . '</li>';
        }
        $content .= '</ul>';
    }

    // 6. Brewer's notes
    $raw_notes = $data['notes'] ?? '';
    if ( is_array( $raw_notes ) ) {
        // Brewfather can send notes as an array of strings or objects
        $raw_notes = implode( "\n", array_map( function( $n ) {
            return is_array( $n ) ? ( $n['note'] ?? $n['text'] ?? json_encode( $n ) ) : (string) $n;
        }, $raw_notes ) );
    }
    $notes = trim( (string) $raw_notes );
    if ( $notes !== '' ) {
        $content .= "<h3>Brewer's Notes</h3><p>" . nl2br( sanitize_textarea_field( $notes ) ) . '</p>';
    }

    // Create the post
    $post_id = wp_insert_post( array(
        'post_title'   => $name,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ) );

    if ( $post_id && ! is_wp_error( $post_id ) ) {
        update_post_meta( $post_id, '_bf_batch_id', $bf_id );
        // Assign to 'Brews' category, creating it if it doesn't exist
        $cat_id = get_cat_ID( 'Brews' );
        if ( ! $cat_id ) {
            $cat_id = wp_create_category( 'Brews' );
        }
        if ( $cat_id ) {
            wp_set_post_categories( $post_id, array( $cat_id ) );
        }
        // Auto-tag by beer style
        $tags = array_values( array_filter( array(
            $style,
            sanitize_text_field( $data['recipe']['style']['category'] ?? '' ),
        ) ) );
        if ( ! empty( $tags ) ) {
            wp_set_post_tags( $post_id, $tags, true );
        }
        // Fetch fermentation readings and store for graph rendering
        $readings = bf_fetch_batch_readings( $bf_id );
        if ( ! is_wp_error( $readings ) && ! empty( $readings ) ) {
            update_post_meta( $post_id, '_bf_readings', wp_json_encode( $readings ) );
        }
        return array( 'success' => true, 'post_id' => $post_id );
    }

    return new WP_Error( 'post_fail', 'Failed to insert post', array( 'status' => 500 ) );
}

// ---------------------------------------------------------------------------
// Brewfather API V2 — fetch all readings for a batch
// ---------------------------------------------------------------------------

function bf_fetch_batch_readings( string $batch_id ) {
    $user_id = get_option( 'bf_user_id' );
    $api_key = get_option( 'bf_api_key' );

    if ( empty( $user_id ) || empty( $api_key ) ) {
        return new WP_Error( 'no_credentials', 'No API credentials configured.' );
    }

    $url      = 'https://api.brewfather.app/v2/batches/' . rawurlencode( $batch_id ) . '/readings';
    $auth     = base64_encode( $user_id . ':' . $api_key );
    $response = wp_remote_get( $url, array(
        'headers' => array( 'Authorization' => 'Basic ' . $auth ),
        'timeout' => 20,
    ) );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'api_error', 'Readings API returned HTTP ' . $code );
    }

    $readings = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $readings ) ? $readings : new WP_Error( 'parse_error', 'Could not parse readings.' );
}

// ---------------------------------------------------------------------------
// Enqueue Chart.js + init script on single posts that have readings
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_single() ) return;
    $post_id = get_queried_object_id();
    $raw     = get_post_meta( $post_id, '_bf_readings', true );
    error_log( 'BF chart check: post=' . $post_id . ' raw_len=' . strlen( (string) $raw ) );
    if ( ! $raw ) return;

    $readings = json_decode( $raw, true );
    error_log( 'BF chart readings count: ' . count( (array) $readings ) );
    if ( empty( $readings ) ) return;

    $labels      = array();
    $temp_data   = array();
    $fridge_data = array();
    $room_data   = array();

    if ( ! empty( $readings[0] ) ) {
        error_log( 'BF readings sample keys: ' . implode( ', ', array_keys( $readings[0] ) ) );
    }

    foreach ( $readings as $r ) {
        if ( empty( $r['time'] ) ) continue;
        $labels[]      = date_i18n( 'j M H:i', (int) ( $r['time'] / 1000 ) );
        $temp_data[]   = isset( $r['temp'] )      ? (float) $r['temp']      : null;
        $fridge_data[] = isset( $r['fridgeTemp'] ) ? (float) $r['fridgeTemp'] : null;
        $room_data[]   = isset( $r['roomTemp'] )   ? (float) $r['roomTemp']   : null;
    }

    wp_enqueue_script(
        'bf-chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
        array(),
        '4',
        true
    );
    wp_enqueue_script(
        'bf-hammerjs',
        'https://cdn.jsdelivr.net/npm/hammerjs@2/hammer.min.js',
        array(),
        '2',
        true
    );
    wp_enqueue_script(
        'bf-chartjs-zoom',
        'https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1/dist/chartjs-plugin-zoom.min.js',
        array( 'bf-chartjs', 'bf-hammerjs' ),
        '1',
        true
    );

    // Attach init code after the zoom plugin so all libraries are loaded.
    $canvas_id = 'bf-chart-' . absint( $post_id );

    // Build datasets array — only include a series if it has at least one real value.
    $has_fridge = count( array_filter( $fridge_data, fn( $v ) => $v !== null ) ) > 0;
    $has_room   = count( array_filter( $room_data,   fn( $v ) => $v !== null ) ) > 0;

    $datasets_php = array(
        array(
            'label'           => 'Fermenter',
            'data'            => $temp_data,
            'yAxisID'         => 'temp',
            'borderColor'     => '#4a90d9',
            'backgroundColor' => 'rgba(74,144,217,0.08)',
            'tension'         => 0.3,
            'pointRadius'     => 1,
            'fill'            => true,
        ),
    );
    if ( $has_fridge ) {
        $datasets_php[] = array(
            'label'           => 'Glycol',
            'data'            => $fridge_data,
            'yAxisID'         => 'temp',
            'borderColor'     => '#00b4d8',
            'backgroundColor' => 'rgba(0,180,216,0.08)',
            'tension'         => 0.3,
            'pointRadius'     => 1,
            'fill'            => false,
        );
    }
    if ( $has_room ) {
        $datasets_php[] = array(
            'label'           => 'Ambient',
            'data'            => $room_data,
            'yAxisID'         => 'temp',
            'borderColor'     => '#57a865',
            'backgroundColor' => 'rgba(87,168,101,0.08)',
            'tension'         => 0.3,
            'pointRadius'     => 1,
            'fill'            => false,
        );
    }

    wp_add_inline_script(
        'bf-chartjs-zoom',
        '(function(){'.
        'var el=document.getElementById(' . wp_json_encode( $canvas_id ) . ');'.
        'if(!el)return;'.
        'new Chart(el,{'.
          '"type":"line",'.
          '"data":{'.
            '"labels":' . wp_json_encode( $labels ) . ','.
            '"datasets":' . wp_json_encode( $datasets_php ) . ''.
          '},'.
          '"options":{'.
            '"responsive":true,'.
            '"interaction":{"mode":"index","intersect":false},'.
            '"plugins":{'.
              '"zoom":{'.
                '"zoom":{"wheel":{"enabled":true},"pinch":{"enabled":true},"mode":"x"},'.
                '"pan":{"enabled":true,"mode":"x"}'.
              '}'.
            '},'.
            '"scales":{'.
              '"temp":{"type":"linear","position":"left","title":{"display":true,"text":"\u00b0C"}}'.
            '}'.
          '}'.
        '});'.
        '})();'
    );
} );

// ---------------------------------------------------------------------------
// Append fermentation graph canvas after post content
// ---------------------------------------------------------------------------

add_filter( 'the_content', function ( $content ) {
    if ( ! is_singular( 'post' ) || ! in_the_loop() ) return $content;
    $post_id = get_the_ID();
    if ( ! get_post_meta( $post_id, '_bf_readings', true ) ) return $content;
    $canvas_id = 'bf-chart-' . absint( $post_id );
    $reset_js = 'var c=Chart.getChart(document.getElementById(' . wp_json_encode( $canvas_id ) . '));if(c)c.resetZoom();';
    return $content
        . '<h3>Fermentation</h3>'
        . '<canvas id="' . esc_attr( $canvas_id ) . '" style="max-height:400px;width:100%;"></canvas>'
        . '<p style="text-align:right;margin-top:4px;"><button onclick="' . esc_attr( $reset_js ) . '" style="font-size:0.8em;padding:2px 8px;">Reset zoom</button></p>';
} );

// ---------------------------------------------------------------------------
// Admin action — re-fetch readings for an existing post
// POST to wp-admin/admin-post.php?action=bf_refresh_readings&post_id=123&_wpnonce=...
// ---------------------------------------------------------------------------

add_action( 'admin_post_bf_refresh_readings', function () {
    if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Forbidden', 403 );
    check_admin_referer( 'bf_refresh_readings' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    $bf_id   = get_post_meta( $post_id, '_bf_batch_id', true );

    if ( ! $post_id || ! $bf_id ) {
        wp_redirect( add_query_arg( 'bf_msg', 'invalid', admin_url( 'options-general.php?page=brewfather-sync' ) ) );
        exit;
    }

    $readings = bf_fetch_batch_readings( $bf_id );
    if ( is_wp_error( $readings ) ) {
        wp_redirect( add_query_arg( 'bf_msg', 'api_error', admin_url( 'options-general.php?page=brewfather-sync' ) ) );
        exit;
    }

    update_post_meta( $post_id, '_bf_readings', wp_json_encode( $readings ) );
    wp_redirect( add_query_arg( array( 'bf_msg' => 'ok', 'post_id' => $post_id ), admin_url( 'options-general.php?page=brewfather-sync' ) ) );
    exit;
} );