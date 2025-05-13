<?php

class WPMLImporterAddon extends \ImportWP\Common\AddonAPI\ImporterAddon
{
    private $_current_lng;
    private $_lng_code;

    protected function can_run()
    {
        $template_id = $this->get_template_id();
        $is_allowed = in_array($template_id, ['page', 'post', 'custom-post-type', 'term', 'woocommerce-product']);
        $is_allowed = apply_filters('iwp/wpml/can_run', $is_allowed, $template_id);
        return $is_allowed;
    }

    public function register($template_data)
    {
        $panel = $template_data->register_panel('WPML');

        $panel->register_field('language', [
            'options' => $this->get_sitepress_languages()
        ]);

        $translation_group = $panel->register_group('Translations', ['id' => '_translation']);

        $translation_group->register_field('Translation', [
            'id' => 'translation',
            'default' => '',
            'tooltip' => __('Set this for the post it belongs to', 'importwp')
        ]);
        $translation_group->register_field('Translation Field Type', [
            'id' => '_translation_type',
            'default' => 'id',
            'options' => [
                ['value' => 'id', 'label' => 'ID'],
                ['value' => 'slug', 'label' => 'Slug'],
                ['value' => 'name', 'label' => 'Name'],
                ['value' => 'column', 'label' => 'Reference Column']
            ],
            'tooltip' => __('Select how the translation field should be handled', 'importwp')
        ]);
        $translation_group->register_field('Translation Reference Column', [
            'id' => '_translation_ref',
            'condition' => ['_translation_type', '==', 'column'],
            'tooltip' => __('Select the column/node that the translation field is referencing', 'importwp')
        ]);
    }

    public function before_import()
    {
        add_filter('iwp/importer/mapper/post_exists_query', [$this, 'suppress_query_filters']);
        add_filter('iwp/woocommerce/importer/product/get_product_id_args', [$this, 'suppress_query_filters']);

        add_filter('iwp/importer/template/post_term', [$this, 'get_translated_terms'], 10, 2);
    }

    public function after_import()
    {
        remove_filter('iwp/importer/mapper/post_exists_query', [$this, 'suppress_query_filters']);
        remove_filter('iwp/woocommerce/importer/product/get_product_id_args', [$this, 'suppress_query_filters']);
        remove_filter('iwp/importer/template/post_term', [$this, 'get_translated_terms']);
    }

    public function suppress_query_filters($query_args)
    {
        $query_args['suppress_filters'] = true;
        return $query_args;
    }

    /**
     * Select translated terms if they exist
     */
    public function get_translated_terms($term, $tax)
    {
        /**
         * @var \SitePress $sitepress
         */
        global $sitepress, $iwp_wpml_lang_code;

        if (!$sitepress->is_translated_taxonomy($tax)) return $term;

        if (!empty($term) and !is_wp_error($term)) {
            $term_id = apply_filters('wpml_object_id', $term->term_id, $tax, true, $iwp_wpml_lang_code);
            $term  = get_term_by('id', $term_id, $tax);
        }
        return $term;
    }

    private function get_sitepress_languages()
    {
        /**
         * @var \SitePress $sitepress
         */
        global $sitepress;

        $languages = [];

        if (isset($sitepress)) {

            $languages = $sitepress->get_active_languages();

            $languages = array_reduce($languages, function ($carry, $item) {
                $carry[] = [
                    'value' => $item['code'],
                    'label' => $item['display_name']
                ];
                return $carry;
            }, []);
        }

        return $languages;
    }

    public function before_row($record)
    {
        /**
         * @var \SitePress $sitepress
         */
        global $sitepress;

        $this->_current_lng = apply_filters('wpml_current_language', null);

        if ($language = $record->get_value('wpml', 'language')) {
            $this->_lng_code = trim($language);
        } else {
            $this->_lng_code = $sitepress->get_default_language();
        }

        // set language of imported item.
        do_action('wpml_switch_language', $this->_lng_code);
    }

    public function after_row()
    {
        // reset language
        do_action('wpml_switch_language', $this->_current_lng);
    }

    public function save($data)
    {
        if ($panel = $data->get_panel('wpml')) {
            if ($panel_data = $panel->get_value()) {

                /**
                 * @var \SitePress $sitepress
                 */
                global $sitepress;

                // save language
                if (isset($panel_data['language'])) {
                    $language = trim($panel_data['language']);
                } else {
                    $language = $sitepress->get_default_language();
                }

                global $iwp_wpml_lang_code;
                $iwp_wpml_lang_code = $language;

                $parent_id = 0;
                $parent_type = isset($panel_data['_translation'], $panel_data['_translation']['_translation_type']) ? trim($panel_data['_translation']['_translation_type']) : false;
                $translation = isset($panel_data['_translation'], $panel_data['_translation']['translation']) ? trim($panel_data['_translation']['translation']) : false;

                // flag this on the post for future searches
                if ($parent_type == 'column') {
                    $data->update_meta('_iwp_wpml_tref', trim($panel_data['_translation']['_translation_ref']));
                }

                if (empty($translation)) {
                    return;
                }

                if ($this->get_template_id() === 'term') {

                    //
                    $taxonomy = iwp()->importer->getSetting('taxonomy');
                    switch ($parent_type) {
                        case 'name':

                            $term = get_term_by('name', $translation, $taxonomy);
                            if ($term) {
                                $parent_id = intval($term->term_id);
                            }

                            break;
                        case 'slug':
                            // name or slug
                            $term = get_term_by('slug', $translation['parent'], $taxonomy);
                            if ($term) {
                                $parent_id = intval($term->term_id);
                            }
                            break;
                        case 'id':
                            $parent_id = intval($translation);
                            break;
                        case 'column':

                            $temp_id = $this->get_term_by_cf('_iwp_wpml_tref', $translation, $taxonomy);
                            if (intval($temp_id > 0) && $temp_id !== $data->get_id()) {
                                $parent_id = intval($temp_id);
                            }

                            break;
                    }

                    $wpml_el_type = 'tax_' . $taxonomy;
                } else {

                    $post_type = iwp()->importer->getSetting('post_type');
                    switch ($parent_type) {
                        case 'name':
                        case 'slug':
                            // name or slug
                            $page = get_posts(array('name' => sanitize_title($translation), 'post_type' => $post_type));
                            if ($page) {
                                $parent_id = intval($page[0]->ID);
                            }
                            break;
                        case 'id':
                            $parent_id = intval($translation);
                            break;
                        case 'column':

                            $temp_id = $this->get_post_by_cf('_iwp_wpml_tref', $translation, $post_type, $data->get_id());
                            if (intval($temp_id > 0)) {
                                $parent_id = intval($temp_id);
                            }

                            break;
                    }

                    $wpml_el_type = 'post_' . get_post_type($data->get_id());
                }

                // create and connect translations
                if ($parent_id !== $data->get_id() && $parent_id > 0) {

                    $trid = $sitepress->get_element_trid($parent_id, $wpml_el_type);
                    if ($trid) {
                        $sitepress->set_element_language_details($data->get_id(), $wpml_el_type, $trid, $language, null, false);
                    }
                }
            }
        }
    }

    public function get_post_by_cf($field, $value, $post_type, $id)
    {
        $query = new \WP_Query(array(
            'post_type' => $post_type,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'post__not_in' => [$id],
            'meta_query' => array(
                array(
                    'key' => $field,
                    'value' => $value
                )
            ),
            'post_status' => 'any',
            // needed for wpml integration
            'suppress_filters' => true
        ));
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return false;
    }

    public function get_term_by_cf($field, $value, $taxonomy)
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'fields' => 'ids',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' =>  $field,
                    'value' => $value,
                    'compare' => '='
                ]
            ]
        ]);

        if (!is_wp_error($terms)) {
            return $terms[0];
        }

        return false;
    }
}
