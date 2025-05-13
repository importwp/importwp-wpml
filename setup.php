<?php
iwp_register_importer_addon('WPML', 'wpml', function (\ImportWP\Common\Addon\AddonInterface $addon) {

    global $iwp_wpml_lang_code;

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

    add_filter('iwp/importer/mapper/post_exists_query', function ($query_args) {
        $query_args['suppress_filters'] = true;
        return $query_args;
    });

    $addon->register_panel('WPML', 'wpml', function (\ImportWP\Common\Addon\AddonBasePanel $panel) {

        /**
         * @var \SitePress $sitepress
         */
        global $sitepress;

        $languages = $sitepress->get_active_languages();

        $languages = array_reduce($languages, function ($carry, $item) {
            $carry[] = [
                'value' => $item['code'],
                'label' => $item['display_name']
            ];
            return $carry;
        }, []);

        $panel->register_field('language', 'language', [
            'options' => $languages
        ])->save(false);

        $panel->register_group('Translations', '_translation', function (\ImportWP\Common\Addon\AddonBaseGroup $group) {
            $group->register_field('Translation', 'translation', [
                'default' => '',
                'tooltip' => __('Set this for the post it belongs to', 'importwp')
            ])->save(false);
            $group->register_field('Translation Field Type', '_translation_type', [
                'default' => 'id',
                'options' => [
                    ['value' => 'id', 'label' => 'ID'],
                    ['value' => 'slug', 'label' => 'Slug'],
                    ['value' => 'name', 'label' => 'Name'],
                    ['value' => 'column', 'label' => 'Reference Column']
                ],
                'type' => 'select',
                'tooltip' => __('Select how the translation field should be handled', 'importwp')
            ])->save(false);
            $group->register_field('Translation Reference Column', '_translation_ref', [
                'condition' => ['_translation_type', '==', 'column'],
                'tooltip' => __('Select the column/node that the translation field is referencing', 'importwp')
            ])->save(false);
        });

        $panel->save(function (\ImportWP\Common\Addon\AddonPanelDataApi $api) {

            $meta = $api->get_meta();
            if (empty($meta)) {
                return;
            }

            /**
             * @var \SitePress $sitepress
             */
            global $sitepress;

            // save language
            if (isset($meta['language'], $meta['language']['value'])) {
                $language = trim($meta['language']['value']);
            } else {
                $language = $sitepress->get_default_language();
            }

            global $iwp_wpml_lang_code;
            $iwp_wpml_lang_code = $language;

            iwp_wpml_set_post_language($api->object_id(), $language);

            $parent_id = 0;
            $post_type = $api->importer_model()->getSetting('post_type');
            $parent_type = isset($meta['_translation_type'], $meta['_translation_type']['value']) ? trim($meta['_translation_type']['value']) : false;
            $translation = isset($meta['translation'], $meta['translation']['value']) ? trim($meta['translation']['value']) : false;

            // flag this on the post for future searches
            if ($parent_type == 'column') {
                $api->update_meta('_iwp_wpml_post_translation', trim($meta['_translation_ref']['value']));
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

                    $temp_id = iwp_wpml_get_post_by_cf('_iwp_wpml_post_translation', $translation, $post_type, $api->object_id());
                    if (intval($temp_id > 0)) {
                        $parent_id = intval($temp_id);
                    }

                    break;
            }

            if ($parent_id !== $api->object_id() && $parent_id > 0) {
                iwp_wpml_set_post_as_translation($api->object_id(), $parent_id, $language);
            }
        });
    });
});

function iwp_wpml_get_post_by_cf($field, $value, $post_type, $id)
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

function iwp_wpml_set_post_language($post_id, $post_language_code = false)
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

function iwp_wpml_set_post_as_translation($post_element, $parent_post_element, $lang)
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
