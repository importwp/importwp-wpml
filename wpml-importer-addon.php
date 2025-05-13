<?php
class WPMLImporterAddon extends \ImportWP\Common\AddonAPI\ImporterAddon
{
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
        add_filter('iwp/importer/mapper/post_exists_query', function ($query_args) {
            $query_args['suppress_filters'] = true;
            return $query_args;
        });

        // select translated terms if they exist
        add_filter('iwp/importer/template/post_term', function ($term, $tax) {

            global $iwp_wpml_lang_code;

            /**
             * @var \SitePress $sitepress
             */
            global $sitepress;

            if (!$sitepress->is_translated_taxonomy($tax)) return $term;

            if (!empty($term) and !is_wp_error($term)) {
                $term_id = apply_filters('wpml_object_id', $term->term_id, $tax, true, $iwp_wpml_lang_code);
                $term  = get_term_by('id', $term_id, $tax);
            }
            return $term;
        }, 10, 2);
    }

    private function get_sitepress_languages()
    {

        /**
         * When running this is null.
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

                $this->set_post_language($data->get_id(), $language);

                $parent_id = 0;
                $post_type = iwp()->importer->getSetting('post_type');
                $parent_type = isset($panel_data['_translation'], $panel_data['_translation']['_translation_type']) ? trim($panel_data['_translation']['_translation_type']) : false;
                $translation = isset($panel_data['_translation'], $panel_data['_translation']['translation']) ? trim($panel_data['_translation']['translation']) : false;

                // flag this on the post for future searches
                if ($parent_type == 'column') {
                    $data->update_meta('_iwp_wpml_post_translation', trim($panel_data['_translation']['_translation_ref']));
                }

                if (empty($translation)) {
                    return;
                }

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

                        $temp_id = $this->get_post_by_cf('_iwp_wpml_post_translation', $translation, $post_type, $data->get_id());
                        if (intval($temp_id > 0)) {
                            $parent_id = intval($temp_id);
                        }

                        break;
                }

                if ($parent_id !== $data->get_id() && $parent_id > 0) {
                    $this->set_post_as_translation($data->get_id(), $parent_id, $language);
                }
            }
        }
    }

    public function set_post_language($post_id, $post_language_code = false)
    {
        /**
         * @var \SitePress $sitepress
         */
        global $sitepress;

        if (!$post_language_code) {
            $post_language_code = get_post_meta($post_id, '_wpml_language', true);
            $post_language_code = $post_language_code ? $post_language_code : $sitepress->get_default_language();
        }

        $wpml_translations = new WPML_Translations($sitepress);
        $post_element      = new WPML_Post_Element($post_id, $sitepress);
        $wpml_translations->set_language_code($post_element, $post_language_code);
    }

    public function set_post_as_translation($post_element, $parent_post_element, $lang)
    {
        /**
         * @var \SitePress $sitepress
         */
        global $sitepress;
        $wpml_translations = new WPML_Translations($sitepress);

        if (!is_a($post_element, 'WPML_Post_Element')) {
            $post_element = new WPML_Post_Element($post_element, $sitepress);
        }

        if (!is_a($parent_post_element, 'WPML_Post_Element')) {
            $parent_post_element = new WPML_Post_Element($parent_post_element, $sitepress);
        }

        $wpml_translations->set_language_code($post_element, $lang);
        $wpml_translations->set_source_element($post_element, $parent_post_element);
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
            'post_status' => 'any'
        ));
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return false;
    }
}
