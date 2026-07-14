<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Category-specific promotional popup for WooCommerce category archives and
 * products assigned to the configured category (including descendants).
 */
class MG_Category_Popup {

    const META_ENABLED     = 'mg_category_popup_enabled';
    const META_TITLE       = 'mg_category_popup_title';
    const META_CONTENT     = 'mg_category_popup_content';
    const META_BUTTON_TEXT = 'mg_category_popup_button_text';
    const META_BUTTON_URL  = 'mg_category_popup_button_url';
    const NONCE_ACTION     = 'mg_save_category_popup';
    const NONCE_NAME       = 'mg_category_popup_nonce';

    /** @var WP_Term|null */
    protected static $popup_term = null;

    public static function init() {
        add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add_fields' ) );
        add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_fields' ) );
        add_action( 'created_product_cat', array( __CLASS__, 'save_fields' ) );
        add_action( 'edited_product_cat', array( __CLASS__, 'save_fields' ) );

        add_filter( 'manage_edit-product_cat_columns', array( __CLASS__, 'add_admin_column' ) );
        add_filter( 'manage_product_cat_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 3 );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( __CLASS__, 'render_popup' ), 30 );
    }

    public static function render_add_fields() {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <div class="form-field">
            <label for="mg-category-popup-enabled">
                <input type="checkbox" name="mg_category_popup_enabled" id="mg-category-popup-enabled" value="1" />
                <?php esc_html_e( 'Kategória popup bekapcsolása', 'mockup-generator' ); ?>
            </label>
            <p><?php esc_html_e( 'A popup ezen a kategóriaoldalon és a kategóriába tartozó termékoldalakon jelenik meg.', 'mockup-generator' ); ?></p>
        </div>
        <?php self::render_text_fields(); ?>
        <?php
    }

    public static function render_edit_fields( $term ) {
        $term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e( 'Kategória popup', 'mockup-generator' ); ?></th>
            <td>
                <label for="mg-category-popup-enabled">
                    <input type="checkbox" name="mg_category_popup_enabled" id="mg-category-popup-enabled" value="1" <?php checked( self::is_enabled( $term_id ) ); ?> />
                    <?php esc_html_e( 'Popup bekapcsolása ennél a kategóriánál', 'mockup-generator' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Megjelenik a kategóriaoldalon és a hozzá tartozó termékoldalakon. Egy látogató egy munkamenetben egyszer látja.', 'mockup-generator' ); ?></p>
            </td>
        </tr>
        <?php self::render_text_fields( $term_id, true ); ?>
        <?php
    }

    protected static function render_text_fields( $term_id = 0, $table = false ) {
        $title       = $term_id ? get_term_meta( $term_id, self::META_TITLE, true ) : '';
        $content     = $term_id ? get_term_meta( $term_id, self::META_CONTENT, true ) : '';
        $button_text = $term_id ? get_term_meta( $term_id, self::META_BUTTON_TEXT, true ) : '';
        $button_url  = $term_id ? get_term_meta( $term_id, self::META_BUTTON_URL, true ) : '';

        $fields = array(
            array(
                'id'          => 'mg-category-popup-title',
                'name'        => 'mg_category_popup_title',
                'label'       => __( 'Popup címe', 'mockup-generator' ),
                'value'       => $title,
                'type'        => 'text',
                'placeholder' => __( 'Csapattal rendelsz?', 'mockup-generator' ),
                'description' => __( 'Üresen hagyva az alapértelmezett cím jelenik meg.', 'mockup-generator' ),
            ),
            array(
                'id'          => 'mg-category-popup-content',
                'name'        => 'mg_category_popup_content',
                'label'       => __( 'Popup szövege', 'mockup-generator' ),
                'value'       => $content,
                'type'        => 'textarea',
                'placeholder' => __( 'Rendeljétek együtt a csapat pólóit, és használjátok ki a mennyiségi kedvezményt!', 'mockup-generator' ),
                'description' => __( 'Egyszerű HTML-formázás használható.', 'mockup-generator' ),
            ),
            array(
                'id'          => 'mg-category-popup-button-text',
                'name'        => 'mg_category_popup_button_text',
                'label'       => __( 'Gomb felirata', 'mockup-generator' ),
                'value'       => $button_text,
                'type'        => 'text',
                'placeholder' => __( 'Megnézem a kedvezményt', 'mockup-generator' ),
                'description' => __( 'Csak akkor jelenik meg külön akciógomb, ha linket is megadsz.', 'mockup-generator' ),
            ),
            array(
                'id'          => 'mg-category-popup-button-url',
                'name'        => 'mg_category_popup_button_url',
                'label'       => __( 'Gomb linkje', 'mockup-generator' ),
                'value'       => $button_url,
                'type'        => 'url',
                'placeholder' => 'https://',
                'description' => __( 'Lehet kedvezményes tájékoztató-, kapcsolat- vagy egyéb oldal.', 'mockup-generator' ),
            ),
        );

        foreach ( $fields as $field ) {
            if ( $table ) {
                echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
            } else {
                echo '<div class="form-field"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label>';
            }

            if ( $field['type'] === 'textarea' ) {
                echo '<textarea name="' . esc_attr( $field['name'] ) . '" id="' . esc_attr( $field['id'] ) . '" rows="5" class="large-text" placeholder="' . esc_attr( $field['placeholder'] ) . '">' . esc_textarea( $field['value'] ) . '</textarea>';
            } else {
                echo '<input type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $field['name'] ) . '" id="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( $field['value'] ) . '" class="regular-text" placeholder="' . esc_attr( $field['placeholder'] ) . '" />';
            }
            echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
            echo $table ? '</td></tr>' : '</div>';
        }
    }

    public static function save_fields( $term_id ) {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ||
            ! current_user_can( 'manage_product_terms' ) ) {
            return;
        }

        self::save_meta( $term_id, self::META_ENABLED, ! empty( $_POST['mg_category_popup_enabled'] ) ? '1' : '' );
        self::save_meta( $term_id, self::META_TITLE, isset( $_POST['mg_category_popup_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mg_category_popup_title'] ) ) : '' );
        self::save_meta( $term_id, self::META_CONTENT, isset( $_POST['mg_category_popup_content'] ) ? wp_kses_post( wp_unslash( $_POST['mg_category_popup_content'] ) ) : '' );
        self::save_meta( $term_id, self::META_BUTTON_TEXT, isset( $_POST['mg_category_popup_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['mg_category_popup_button_text'] ) ) : '' );
        self::save_meta( $term_id, self::META_BUTTON_URL, isset( $_POST['mg_category_popup_button_url'] ) ? esc_url_raw( wp_unslash( $_POST['mg_category_popup_button_url'] ) ) : '' );
    }

    protected static function save_meta( $term_id, $key, $value ) {
        if ( $value === '' ) {
            delete_term_meta( $term_id, $key );
        } else {
            update_term_meta( $term_id, $key, $value );
        }
    }

    public static function add_admin_column( $columns ) {
        $columns['mg_category_popup'] = __( 'Popup', 'mockup-generator' );
        return $columns;
    }

    public static function render_admin_column( $content, $column_name, $term_id ) {
        if ( $column_name === 'mg_category_popup' ) {
            return self::is_enabled( $term_id ) ? '<span aria-label="' . esc_attr__( 'Bekapcsolva', 'mockup-generator' ) . '">✓</span>' : '—';
        }
        return $content;
    }

    public static function enqueue_assets() {
        self::$popup_term = self::resolve_popup_term();
        if ( ! self::$popup_term ) {
            return;
        }

        $ver      = defined( 'MG_VERSION' ) ? MG_VERSION : '2.17.1';
        $base_url = plugins_url( '', dirname( __DIR__ ) . '/mockup-generator.php' );

        wp_enqueue_style( 'mg-category-popup', $base_url . '/assets/css/mg-category-popup.css', array(), $ver );
        wp_enqueue_script( 'mg-category-popup', $base_url . '/assets/js/mg-category-popup.js', array(), $ver, true );
    }

    protected static function resolve_popup_term() {
        if ( is_product_category() ) {
            $term = get_queried_object();
            return $term instanceof WP_Term ? self::find_enabled_term( array( $term ) ) : null;
        }

        if ( ! is_product() ) {
            return null;
        }

        $product_id = get_queried_object_id();
        $terms      = wc_get_product_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        return is_wp_error( $terms ) ? null : self::find_enabled_term( $terms );
    }

    protected static function find_enabled_term( $terms ) {
        $candidates = array();
        foreach ( (array) $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $candidates[ $term->term_id ] = $term;
            foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
                $ancestor = get_term( $ancestor_id, 'product_cat' );
                if ( $ancestor instanceof WP_Term ) {
                    $candidates[ $ancestor->term_id ] = $ancestor;
                }
            }
        }

        $enabled = array_filter( $candidates, function( $term ) {
            return self::is_enabled( $term->term_id );
        } );

        usort( $enabled, function( $a, $b ) {
            return count( get_ancestors( $b->term_id, 'product_cat', 'taxonomy' ) ) <=> count( get_ancestors( $a->term_id, 'product_cat', 'taxonomy' ) );
        } );

        return isset( $enabled[0] ) ? $enabled[0] : null;
    }

    protected static function is_enabled( $term_id ) {
        return get_term_meta( (int) $term_id, self::META_ENABLED, true ) === '1';
    }

    public static function render_popup() {
        if ( ! self::$popup_term ) {
            return;
        }

        $term_id     = (int) self::$popup_term->term_id;
        $title       = get_term_meta( $term_id, self::META_TITLE, true );
        $content     = get_term_meta( $term_id, self::META_CONTENT, true );
        $button_text = get_term_meta( $term_id, self::META_BUTTON_TEXT, true );
        $button_url  = get_term_meta( $term_id, self::META_BUTTON_URL, true );

        $title       = $title !== '' ? $title : __( 'Csapattal rendelsz?', 'mockup-generator' );
        $content     = $content !== '' ? $content : __( 'Rendeljétek együtt a csapat pólóit, és használjátok ki a mennyiségi kedvezményt!', 'mockup-generator' );
        $button_text = $button_text !== '' ? $button_text : __( 'Megnézem a kedvezményt', 'mockup-generator' );
        ?>
        <div class="mg-category-popup" data-popup-key="mg-category-popup-<?php echo esc_attr( $term_id ); ?>" aria-hidden="true">
            <div class="mg-category-popup__backdrop" data-mg-category-popup-close></div>
            <section class="mg-category-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="mg-category-popup-title-<?php echo esc_attr( $term_id ); ?>">
                <button type="button" class="mg-category-popup__close" data-mg-category-popup-close aria-label="<?php esc_attr_e( 'Bezárás', 'mockup-generator' ); ?>">&times;</button>
                <div class="mg-category-popup__badge" aria-hidden="true">%</div>
                <p class="mg-category-popup__eyebrow"><?php esc_html_e( 'Csapatkedvezmény', 'mockup-generator' ); ?></p>
                <h2 id="mg-category-popup-title-<?php echo esc_attr( $term_id ); ?>"><?php echo esc_html( $title ); ?></h2>
                <div class="mg-category-popup__content"><?php echo wp_kses_post( wpautop( $content ) ); ?></div>
                <div class="mg-category-popup__actions">
                    <?php if ( $button_url !== '' ) : ?>
                        <a class="mg-category-popup__button" href="<?php echo esc_url( $button_url ); ?>"><?php echo esc_html( $button_text ); ?></a>
                    <?php endif; ?>
                    <button type="button" class="mg-category-popup__dismiss" data-mg-category-popup-close><?php esc_html_e( 'Most nem', 'mockup-generator' ); ?></button>
                </div>
            </section>
        </div>
        <?php
    }
}
