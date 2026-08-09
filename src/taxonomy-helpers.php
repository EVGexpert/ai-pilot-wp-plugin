<?php
/**
 * Shared taxonomy helpers for AI Pilot agent actions.
 *
 * Loaded by the monolithic runtime and by the optional module-agent source.
 *
 * @package AI_Pilot
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize taxonomy input to a flat list.
 *
 * Supports scalar IDs/names, comma/semicolon/newline-separated strings,
 * JSON arrays and ordinary arrays.
 *
 * @param mixed $value
 * @return array
 */
function aipilot_normalize_term_items($value) {
    if ($value === null || $value === false || $value === '') {
        return [];
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        if (substr($value, 0, 1) === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_string($value)) {
            $value = preg_split('/[,;\r\n]+/u', $value);
        }
    } elseif (!is_array($value)) {
        $value = [$value];
    }

    $items = [];
    array_walk_recursive($value, function ($item) use (&$items) {
        if (is_int($item) || is_float($item)) {
            if ((int) $item > 0) {
                $items[] = (int) $item;
            }
            return;
        }

        if (is_string($item)) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
    });

    return $items;
}

/**
 * Extract a term ID from term_exists() response.
 *
 * @param mixed $term
 * @return int
 */
function aipilot_term_exists_id($term) {
    if (is_array($term) && isset($term['term_id'])) {
        return (int) $term['term_id'];
    }
    if (is_object($term) && isset($term->term_id)) {
        return (int) $term->term_id;
    }
    if (is_numeric($term)) {
        return (int) $term;
    }
    return 0;
}

/**
 * Resolve category IDs from IDs and/or names, creating missing named categories.
 *
 * @param mixed $raw_items
 * @return array
 */
function aipilot_resolve_category_ids($raw_items) {
    $ids = [];
    $errors = [];

    foreach (aipilot_normalize_term_items($raw_items) as $item) {
        $is_numeric_id = is_int($item) || (is_string($item) && preg_match('/^\d+$/', trim($item)));

        if ($is_numeric_id) {
            $requested_id = (int) $item;
            $existing = term_exists($requested_id, 'category');
            $term_id = aipilot_term_exists_id($existing);
            if ($term_id > 0) {
                $ids[] = $term_id;
            } else {
                $errors[] = sprintf('Category ID %d not found', $requested_id);
            }
            continue;
        }

        $name = sanitize_text_field($item);
        if ($name === '') {
            continue;
        }

        $existing = term_exists($name, 'category');
        $term_id = aipilot_term_exists_id($existing);

        if ($term_id <= 0) {
            $created = wp_insert_term($name, 'category');
            if (is_wp_error($created)) {
                $errors[] = $created->get_error_message();
                continue;
            }
            $term_id = aipilot_term_exists_id($created);
        }

        if ($term_id > 0) {
            $ids[] = $term_id;
        }
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));

    return [
        'ids' => $ids,
        'errors' => array_values(array_unique($errors)),
    ];
}

/**
 * Resolve tag input to tag names. Numeric IDs are converted to names;
 * text names are passed to wp_set_post_tags(), which creates missing tags.
 *
 * @param mixed $raw_items
 * @return array
 */
function aipilot_resolve_tag_names($raw_items) {
    $tags = [];
    $errors = [];
    $seen = [];

    foreach (aipilot_normalize_term_items($raw_items) as $item) {
        $name = '';

        if (is_int($item) || (is_string($item) && preg_match('/^\d+$/', trim($item)))) {
            $requested_id = (int) $item;
            $existing = term_exists($requested_id, 'post_tag');
            $term_id = aipilot_term_exists_id($existing);

            if ($term_id <= 0) {
                $errors[] = sprintf('Tag ID %d not found', $requested_id);
                continue;
            }

            $term = get_term($term_id, 'post_tag');
            if (!$term || is_wp_error($term) || empty($term->name)) {
                $errors[] = sprintf('Tag ID %d could not be resolved', $requested_id);
                continue;
            }
            $name = sanitize_text_field($term->name);
        } else {
            $name = sanitize_text_field($item);
        }

        if ($name === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $tags[] = $name;
        }
    }

    return [
        'tags' => $tags,
        'errors' => array_values(array_unique($errors)),
    ];
}

/**
 * Apply categories and tags to a post.
 *
 * @param int   $post_id
 * @param array $params
 * @param bool  $clear_when_present Allow explicitly empty arrays/strings to clear terms.
 * @return array
 */
function aipilot_apply_post_terms($post_id, $params, $clear_when_present = false) {
    $result = [
        'category_ids' => [],
        'tag_names' => [],
        'tag_ids' => [],
        'errors' => [],
    ];

    $has_categories = array_key_exists('categories', $params) || array_key_exists('category', $params);
    $category_input = array_key_exists('categories', $params)
        ? $params['categories']
        : ($params['category'] ?? null);

    if ($has_categories) {
        $normalized_categories = aipilot_normalize_term_items($category_input);
        if (empty($normalized_categories) && $clear_when_present) {
            wp_set_post_categories($post_id, [], false);
        } elseif (!empty($normalized_categories)) {
            $resolved = aipilot_resolve_category_ids($normalized_categories);
            $result['category_ids'] = $resolved['ids'];
            $result['errors'] = array_merge($result['errors'], $resolved['errors']);
            if (!empty($result['category_ids'])) {
                wp_set_post_categories($post_id, $result['category_ids'], false);
            }
        }
    }

    $has_tags = array_key_exists('tags', $params) || array_key_exists('tag', $params);
    $tag_input = array_key_exists('tags', $params)
        ? $params['tags']
        : ($params['tag'] ?? null);

    if ($has_tags) {
        $normalized_tags = aipilot_normalize_term_items($tag_input);
        if (empty($normalized_tags) && $clear_when_present) {
            wp_set_post_tags($post_id, [], false);
        } elseif (!empty($normalized_tags)) {
            $resolved = aipilot_resolve_tag_names($normalized_tags);
            $result['tag_names'] = $resolved['tags'];
            $result['errors'] = array_merge($result['errors'], $resolved['errors']);
            if (!empty($result['tag_names'])) {
                wp_set_post_tags($post_id, $result['tag_names'], false);
            }
        }
    }

    if (function_exists('wp_get_post_tags')) {
        $applied_tags = wp_get_post_tags($post_id);
        if (is_array($applied_tags)) {
            foreach ($applied_tags as $term) {
                if (is_object($term) && isset($term->term_id)) {
                    $result['tag_ids'][] = (int) $term->term_id;
                }
            }
            $result['tag_ids'] = array_values(array_unique($result['tag_ids']));
        }
    }

    $result['errors'] = array_values(array_unique($result['errors']));

    return $result;
}

